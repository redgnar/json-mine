# Simple value constraints (`#[Constraints]`)

Date: 2026-08-14. Status: **implemented** (2026-08-14, as planned; the `unique_items` error
points at the duplicate occurrence and its message names the original index). Builds on the `#[Format]` precedent
(implemented 2026-08-13): attribute → `MetadataFactory` attaches to the `TypeNode` →
`Hydrator` validates during hydration → `SchemaGenerator` emits the matching JSON Schema keyword.

## Goal

Let target classes declare simple per-member value constraints that are:

1. **enforced by the mapping engine** (a hydrated object is proof the constraints hold —
   "parse, don't validate"), and
2. **expressible in JSON Schema** — `SchemaGenerator` emits them, so a generated schema and
   the engine agree, and externally-authored schemas can state the same rules.

Everything here is restricted to keywords from the JSON Schema draft 2020-12 **validation
vocabulary** — no invented semantics. Anything JSON Schema cannot express (cross-field rules,
conditional requirements) stays out: that is `ObjectValidator` territory
([03-custom-validation-design.md](03-custom-validation-design.md)).

## Decisions

- **One attribute, `#[Constraints]`, with named arguments mirroring JSON Schema keyword names**
  (decided 2026-08-14; chosen over per-keyword attributes to keep the attribute set small).
- `#[Format]` stays a separate attribute — `Constraints` has no `format` argument. Both may
  appear on the same member; the checks are independent.
- Constraints are validated by the engine **always**, whether or not a schema pre-check ran —
  same as `#[Format]`. The schema pre-check is an optional first line; the engine is the guarantee.
- Per-keyword machine-readable error codes (see below), consistent with the existing
  `mapping.format` / `mapping.enum` style.

## Attribute

`src/Attribute/Constraints.php` — dependency-free (scalar fields only, per the Deptrac rule
that attributes depend on nothing):

```php
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Constraints
{
    public function __construct(
        // string members
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
        // int|float members
        public int|float|null $minimum = null,
        public int|float|null $maximum = null,
        public int|float|null $exclusiveMinimum = null,
        public int|float|null $exclusiveMaximum = null,
        public int|float|null $multipleOf = null,
        // list members
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public ?bool $uniqueItems = null,
        // map members
        public ?int $minProperties = null,
        public ?int $maxProperties = null,
    ) {}
}
```

Usage:

```php
final readonly class Money
{
    public function __construct(
        #[Constraints(minLength: 3, maxLength: 3, pattern: '^[A-Z]{3}$')]
        public string $currency,
        #[Constraints(minimum: 0)]
        public float $amount,
    ) {}
}
```

### Keyword → member-type matrix

| Keywords | Applies to | JSON Schema target |
|---|---|---|
| `minLength`, `maxLength`, `pattern` | `string` | `type: string` node |
| `minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum`, `multipleOf` | `int`, `float` | `type: integer/number` node |
| `minItems`, `maxItems`, `uniqueItems` | `list<T>` | `type: array` node |
| `minProperties`, `maxProperties` | `array<string, T>` (map) | `type: object` node |

A `?T` member accepts the constraints of `T` (the nullable wrapper is descended, exactly like
`applyFormat()` does). `null` values skip constraint checks — constraints describe the non-null
branch, mirroring the generated `anyOf: [<constrained T>, {type: null}]` shape.

**Not supported (configuration error):** constraints on `enum` members (backed enums already
restrict values), `\DateTimeImmutable` (use `#[Format]`), class/`mixed` members, and any
keyword whose group does not match the member's type (e.g. `minLength` on `int`).

## Metadata & type-node changes

- New value object `Ingot\Mapping\Type\ConstraintSet` (readonly, nullable per-keyword fields,
  one group per instance is *not* required — it simply carries whatever was declared for the
  matching group).
- `ScalarType`, `ListType`, `MapType` gain an optional `?ConstraintSet $constraints = null`
  member — same pattern as `ScalarType::$format`.
- `MetadataFactory` gets `applyConstraints(TypeNode, Constraints, string $owner): TypeNode`,
  called next to `applyFormat()` for both parameters and properties. It:
  - descends through `NullableType`,
  - routes each declared keyword to the member's type-node kind; **any keyword from a
    non-matching group throws `\LogicException`** (configuration error, like unknown formats),
  - validates the declaration itself (also `\LogicException`):
    - lengths/counts ≥ 0,
    - `min* ≤ max*` when both bounds of a pair are set (also `exclusiveMinimum < exclusiveMaximum`),
    - `multipleOf > 0`,
    - `pattern` must compile (see "Pattern semantics"),
    - an empty `#[Constraints]` (all arguments null) is rejected — a dead attribute is a mistake.

Cache note: `ClassMetadata` is PSR-6-serialized; `ConstraintSet` must survive serialization
like the rest of the type tree (plain readonly data object — nothing special needed, but the
test suite should cover a cache round-trip).

## Engine (Hydrator) semantics

Checks run **after** the type check succeeds (and after Lax coercion — the engine validates the
value it will actually store; note the raw-vs-coerced nuance under "Known divergences").
Failures use `fail()` with the member's absolute pointer, the offending value, and one of:

| Code | Message shape |
|---|---|
| `mapping.min_length` / `mapping.max_length` | `Must be at least/at most N characters, got M.` |
| `mapping.pattern` | `"…" does not match pattern "…".` |
| `mapping.minimum` / `mapping.maximum` | `Must be >= N / <= N.` |
| `mapping.exclusive_minimum` / `mapping.exclusive_maximum` | `Must be > N / < N.` |
| `mapping.multiple_of` | `Must be a multiple of N.` |
| `mapping.min_items` / `mapping.max_items` | `Must contain at least/at most N items, got M.` |
| `mapping.unique_items` | `Items must be unique — duplicate at index N.` |
| `mapping.min_properties` / `mapping.max_properties` | `Must contain at least/at most N properties, got M.` |

All violated constraints on a member are reported (aggregated), not just the first — consistent
with the one-report philosophy.

Implementation notes:

- **String length = Unicode code points** (`mb_strlen($value, 'UTF-8')`), per the JSON Schema
  spec — not bytes.
- **`multipleOf`**: integer/integer via `%`; anything involving floats via a
  division-and-round check with an epsilon (`abs($q - round($q)) < 1e-9`), documented as such.
- **`uniqueItems`** compares the *raw JSON items* (before item hydration) by canonical
  equality: recursively normalize (sort object keys), then compare the `json_encode` forms.
  JSON Schema treats `1` and `1.0` as equal numbers — `json_encode` under
  `serialize_precision=-1` already emits whole floats without a fraction, so numbers compare
  by value with no extra normalization. Each duplicate occurrence lands in the error report,
  pointing at the duplicate and naming the original index.
- `minItems`/`minProperties` checks run on the raw array/object before per-item recursion, so
  count errors don't drown in per-item errors.

## Pattern semantics (PCRE vs ECMA-262)

JSON Schema `pattern` is an **unanchored ECMA-262 regex without delimiters**. Rules:

- The attribute takes the pattern **without delimiters** (`'^[A-Z]{3}$'`), exactly as it will
  appear in the schema. `SchemaGenerator` emits it verbatim.
- The engine wraps it as `'/…/u'` for `preg_match()` (escaping `/` occurrences), and matches
  unanchored — authors anchor explicitly with `^`/`$`, same as in JSON Schema.
- `MetadataFactory` compiles the pattern once at metadata-build time; a non-compiling pattern
  is a `\LogicException`.
- **Documented caveat**: authors must stay in the PCRE ∩ ECMA-262 common subset (no lookbehind
  quirks, no PCRE-only verbs, no `\A`/`\z`) so the engine and any external validator agree.
  We do not attempt to translate between the dialects.

## SchemaGenerator changes

- `scalar()` additionally emits the string/number keywords from `ScalarType::$constraints`.
- `ListType` node emits `minItems`/`maxItems`/`uniqueItems`; `MapType` node emits
  `minProperties`/`maxProperties` next to `additionalProperties`.
- Nullable members keep the existing `anyOf` shape — constraints ride on the inner branch,
  matching engine behavior (null skips checks).
- Emit only declared keywords (no defaults) — generated schemas stay minimal.

`Normalizer` is untouched — constraints restrict input, they don't change output shape.

## Known divergences to document (not solve)

- **Lax coercion**: the engine checks the coerced value (`"123"` → `123` → `minimum` applies to
  `123`), while a schema validator checks the raw document (where `"123"` fails `type: integer`
  before `minimum` is reached). Strict mode — the default — has no such gap.
- **Float `multipleOf`** epsilon may disagree with opis on adversarial values; acceptable for
  "simple validation".

## Testing plan (per CLAUDE.md rules)

Tests mirror `src/`; GIVEN/WHEN/THEN; error paths assert pointer + code + input.

1. `tests/Mapping/Metadata/MetadataFactoryTest.php` — configuration errors: wrong-group
   keyword per type kind, negative lengths, `min > max`, `multipleOf <= 0`, invalid pattern,
   empty attribute, nullable descent, PSR-6 round-trip of `ConstraintSet`.
2. `tests/Mapping/HydratorTest.php` (or a dedicated `ConstraintsTest`) — one behavior per test:
   pass + fail per keyword; boundary values (`minLength: 3` with a 3-char string passes);
   multi-byte string length; `null` on a nullable constrained member passes; aggregation of
   several violations on one member; pointer correctness inside lists (`/items/2`).
3. `tests/SchemaGen/SchemaGeneratorTest.php` — every keyword lands on the right node; nullable
   wrapping; pattern emitted verbatim.
4. **Round-trip integration test**: for a fixture class, generate the schema, then feed the
   same invalid documents through (a) the opis pre-check and (b) the bare engine — both must
   reject, and matching valid documents must pass both. This pins the "engine and schema
   agree" promise.
5. Mutation gate: `make ci` (includes Infection, minMsi 90 / minCoveredMsi 100) must stay green.

## Implementation steps

1. `src/Attribute/Constraints.php` (+ docblock in the `#[Format]` style).
2. `ConstraintSet` value object; extend `ScalarType`/`ListType`/`MapType`.
3. `MetadataFactory::applyConstraints()` + configuration-error checks.
4. `Hydrator` enforcement + error codes.
5. `SchemaGenerator` keyword emission.
6. Tests (1–4 above), fixtures in `examples/` extended with at least one constrained member
   (living documentation).
7. Docs: README section; add the `#[Constraints]` row to the attribute table in
   [04-core-api-sketch.md](04-core-api-sketch.md).
8. `make ci` green.

## Out of scope (explicitly)

- `const` / literal-value members, `contains`/`minContains`, `dependentRequired`,
  `if/then/else` — either not "simple" or cross-field; plugins/`ObjectValidator` handle those.
- Constraint keywords on `#[Extras]` bags.
- Translating regex dialects.
- Reading constraints *from* an external schema into the engine (schema → metadata direction);
  the flow stays metadata → schema.
