# MVP analysis: a PHP library for typed handling of JSON definitions

Analysis date: 2026-08-08. Detailed source reports: [raw/php-ecosystem.md](raw/php-ecosystem.md), [raw/other-ecosystems.md](raw/other-ecosystems.md).

## 1. Problem statement

We want to: read a JSON definition → (optionally) validate it against a JSON Schema → get a typed tree of PHP objects (converted types and structures), without manual casting and hand-written mapping code.

## 2. Verdict: does the library make sense?

**Yes — there is a real gap.** In PHP (as of 2026) there is **no** modern, actively maintained runtime library that, in a single pass, accepts raw JSON plus an external JSON Schema (2020-12), validates it with JSON Pointer error paths, and returns a typed tree of PHP 8.2+ objects. The current state is assembling building blocks:

| Library | JSON Schema validation | Object hydration | Notes |
|---|---|---|---|
| `opis/json-schema` | ✅ 2020-12 | ❌ | slow release cadence |
| `jsonrainbow/json-schema` | ✅ up to 2019-09 | ❌ | most popular, no 2020-12 |
| `cuyz/valinor` | ❌ (PHP types only) | ✅ best in class | gold standard of mapping |
| `swaggest/php-json-schema` | ✅ up to draft-07 | ✅ | the only "2 in 1", but archaic and fading |
| `spatie/laravel-data` | ❌ (Laravel dialect) | ✅ | Laravel only |
| `wol-soft/model-generator` | ✅ (compiled-in) | ✅ (codegen) | build step, not runtime |

The de facto standard combination is `opis/json-schema` → `cuyz/valinor`: two passes over the data, two type definitions (schema + classes), two error formats.

**Important timing context:** an RFC targeting PHP 8.6 (November 2026) adds a native `JsonSchema` class (validation of drafts 04–2020-12 in `json_decode()`/`json_validate()`), while explicitly deferring hydration to "Future Scope". Strategic conclusion: **the pure validation layer will be free in the language within ~a year — the library's value must lie in schema-integrated hydration and ergonomics, not in the validator itself.**

## 3. Patterns from other ecosystems worth adopting

From the analysis of Pydantic v2, Zod 4, serde, Jackson, kotlinx.serialization, Ajv, msgspec (details in the source report):

1. **Parse, don't validate** — the function accepts `string|mixed` and returns a typed object; correctness is encoded in the return type. In PHP: a readonly class cannot exist in an unvalidated state after its constructor runs.
2. **Single pass**: decoding = validation = construction (Pydantic core, msgspec, serde) — the biggest lever for both performance and ergonomics.
3. **Two error APIs**: throwing (`map()`) and Result-style (`tryMap()` → success/error list), à la `parse`/`safeParse`.
4. **Error aggregation with JSON Pointer paths** + machine-readable error code + input value; human-readable pretty-print kept separate.
5. **Explicit coercion modes** strict/lax with a documented rules table; looser rules for JSON input (string→date is fine, since JSON has no date type).
6. **First-class discriminated unions** (discriminator field → class), backed enums as discriminators.
7. **Optional ≠ nullable** — a parameter with a default = optional; `?Type` = nullable. The semantics already exist in PHP; they just need to be honored.
8. **Compile/cache once, use many times** (Ajv compile, Pydantic core schema); in PHP: reflection with cache + optional codegen for hot paths (~10× on nested DTOs).
9. **Single source of truth** — the marshmallow anti-pattern (schema next to class = drift) is the main reason old libraries are being abandoned.

2026 trend: **model-first with JSON Schema export wins on ergonomics** (Pydantic, Zod 4 `z.toJSONSchema()`, serde+schemars); schema-first remains a contract-oriented niche (OpenAPI, cross-team configurations).

## 4. The key design tension: where does the truth about types come from?

This question determines the entire shape of the library. Our case ("assorted definitions in JSON, schema comes from the outside") suggests schema-first, but it needs to be settled:

- **Schema-first**: the JSON Schema is the contract (e.g. definitions from external systems). PHP classes are secondary — generated, or non-existent altogether.
- **Model-first**: PHP classes are the source of truth; the schema is generated, or used only as an extra pre-check.

## 5. MVP variants for discussion

### Variant A — "Schema-aware mapper" (runtime, fills the gap directly)

One call: `$mapper->map(User::class, $json, schema: $schema)` — schema validation and hydration in a single tree pass, unified error format (JSON Pointer). Schema↔class mapping inferred from field names + attributes for adjustments.

- **Pros:** exactly the gap nobody fills; a natural migration from the opis+valinor stack.
- **Risks:** a full 2020-12 validator implementation is a big cost (`$ref`, `$dynamicRef`, unevaluatedProperties…); decide: own validator vs delegating to `opis/json-schema` (and from PHP 8.6 — to the native `JsonSchema`) with hydration "bolted on".
- **MVP:** delegate validation to an existing validator, own the hydration + error unification; an own single-pass engine as stage 2.

### Variant B — "Pydantic for PHP" (model-first)

Readonly classes + attributes (`#[Discriminator]`, `#[Format]`, `#[Pattern]`…) as the source of truth; JSON Schema generated from classes (the schemars pattern); an external schema optionally as a pre-check.

- **Pros:** best ergonomics, aligned with the 2026 trend.
- **Risks:** **head-on competition with Valinor** (very active, mature) — a clear differentiator is required (ours: two-way JSON Schema integration).

### Variant C — "Typed tree without classes" (schema-first, dynamic)

The user writes no classes at all: from a JSON Schema + document a typed tree emerges (nodes accessed as `$node->foo->bar`, types converted per the schema: `format: date-time` → `DateTimeImmutable`, enum → enum-like, etc.), plus an optional class/PHPDoc stub generator for static analysis.

- **Pros:** unique — nobody does this; ideal when there are many definitions and writing classes per definition doesn't scale ("assorted definitions" from the requirements!).
- **Risks:** without classes there is no IDE/PHPStan support out of the box — stubs must be generated; easy to end up with a "better stdClass".

### Variant D — hybrid A+C (recommendation to consider)

Core: an engine "schema + document → validated, converted tree" (C). On top of it two fronts: hydration into user classes when they exist (A), and dynamic access + stub generator when they don't. Shared error format and a single coercion table.

## 6. Open questions (to settle before MVP design)

1. **What are the "definitions" in practice?** Config files? API contracts? Process/form definitions from external systems? How many are there and how often do they change? → settles A vs C.
2. **Does the consumer control the PHP classes** (can write them per definition), or are the definitions too numerous/volatile? → settles whether classes are mandatory.
3. **Which JSON Schema drafts** actually occur in our definitions? (If only draft-07 — lower barrier to entry.)
4. **Build our own validator or delegate** (opis/json-schema now, native `JsonSchema` from PHP 8.6)?
5. **Target PHP version** — 8.2 (wider reach) vs 8.3/8.4 (newer capabilities)?
6. **Performance**: will there be a hot path justifying codegen/caching of compiled mappers?

## 7. Resolutions (updated 2026-08-08 after discussion)

- First consumer: **a form definition project** — see [01-forms-context.md](01-forms-context.md).
- Second consumer: **process/workflow definitions (BPMN/n8n-like)** — see [02-process-context.md](02-process-context.md); adds requirements for referential integrity rules, open discriminated unions, and lossless round-trip.
- **No own validator** — delegation confirmed.
- Target PHP: **>= 8.4** (8.5 possible).
- Performance matters — data read/write is a hot path.
- Direction: **variant D** (hybrid), driven by the forms use case which needs both fronts.
- **Own mapper engine** (not Valinor behind a facade) — the extension points (validator registry in the hydration loop, open unions, round-trip, future codegen) sit too deep, and the user wants to avoid a hard dependency for their own large needs.
- **Scope of this phase: the core library only.** Forms are downgraded to a *simple test example* inside the repo (fixtures/examples), not a real package — the actual forms project comes later. Single package for now; no monorepo split yet.
- Advanced/conditional rules (if/then, business rules): **out of core scope** — plugin territory via the `ObjectValidator` extension point ([03-custom-validation-design.md](03-custom-validation-design.md)).
- Core API sketch: [04-core-api-sketch.md](04-core-api-sketch.md).
