 # Core API sketch

Date: 2026-08-08. Based on decisions in [00-mvp-analysis.md](00-mvp-analysis.md) §7. Own mapper engine; single package; PHP >= 8.4. Everything here is a *sketch* for discussion — names and shapes are up for debate.

## Design principles (recap)

1. **Parse, don't validate** — entry points accept raw JSON/arrays and return typed objects; a readonly instance is proof of validity.
2. **One aggregated error report** for all three surfaces: schema validation, type mapping, semantic validators. Every error carries an absolute JSON Pointer + machine-readable code + input value.
3. **Core stays small**: schema validation is delegated, conditional/business rules are plugins, nothing domain-specific inside.
4. **Extension points are first-class**: validator registry, open discriminator registry, caster hooks — all wired into the hydration loop from day 1.

## Entry point

```php
use JsonMine\MapperBuilder;
use JsonMine\Source;
use JsonMine\Coercion;

$mapper = MapperBuilder::create()
    // Schema backend (delegation; swappable for native JsonSchema in PHP 8.6+)
    ->withSchemaValidator(new OpisSchemaValidator())
    // Schema vault: class → schema bindings, registered once (see "Schema vault" below)
    ->withSchema(Workflow::class, Schema::fromFile(__DIR__.'/schemas/workflow-1.0.json'))
    ->withSchema(FormDefinition::class, 'https://example.com/schemas/form-definition.json')
    // Metadata + compiled-schema cache — any PSR-6 pool (e.g. symfony/cache)
    ->withCache(new FilesystemAdapter(namespace: 'json-mine', directory: $dir))
    // Coercion mode — Strict is the DEFAULT (decided: keep things as strict as possible);
    // Lax is opt-in (documented coercion table: "123" → 123, string → DateTimeImmutable, …)
    ->withCoercion(Coercion::Strict)
    // Semantic validators (see 03-custom-validation-design.md)
    ->withValidator(Workflow::class, new GraphIntegrityValidator())
    ->withValidatorFactory(Workflow::class, fn () => $container->get(AclValidator::class))
    // Open discriminated unions (see below)
    ->withVariant(Node::class, 'http', HttpNode::class)          // runtime registration (plugins)
    ->withVariantFallback(Node::class, GenericNode::class)       // unknown variant → preserve, don't fail
    ->build();
```

`MapperBuilder` is immutable/fluent; `build()` compiles and caches what it can. Build once (bootstrap/DI), map many times.

## Mapping

```php
// Throwing style — returns the typed object or throws MappingFailed with the full report.
// The schema registered for Workflow::class in the vault is applied automatically.
$workflow = $mapper->map(Workflow::class, Source::json($rawJson));

// Result style — never throws for data errors
$result = $mapper->tryMap(Workflow::class, Source::json($rawJson));
if (!$result->isSuccess()) {
    foreach ($result->errors() as $error) {
        // $error->pointer(): '/nodes/3/type'  $error->code(): 'mapping.unknown_variant'  $error->message()  $error->input()
    }
} else {
    $workflow = $result->value();
}

// Generic targets via type strings (PHPStan-friendly; the docblock type is the contract)
/** @var list<Field> $fields */
$fields = $mapper->map('list<Field>', Source::array($decoded));
```

### Schema vault (class → schema bindings)

The caller should not have to attach a schema per call — the mapper already knows the target class, so the class→schema binding is registered **once, on the builder** (decided 2026-08-08):

```php
// Simple form — sugar on the builder:
->withSchema(Workflow::class, Schema::fromFile('schemas/workflow-1.0.json'))
->withSchema(FormDefinition::class, 'https://example.com/schemas/form-definition.json') // URI, lazily loaded

// Dynamic resolver — a registered function that answers whether (and which) schema
// applies to a given class; `null` = no schema for this class (decided 2026-08-08):
->withSchemaResolver(
    fn (string $class, mixed $document): ?Schema
        => $myRegistry->schemaFor($class, $document)   // conventions, plugin discovery, version from $document…
)

// Or a vault object, when bindings come from elsewhere (DI):
->withSchemaVault($vault)
```

```php
interface SchemaVault
{
    public function has(string $class): bool;                          // "is there a schema for this class?"
    public function resolve(string $class, mixed $document): ?Schema;  // null = map without schema pre-check
}
```

Rules:
- `map(Workflow::class, ...)` automatically runs the pre-check with the resolved schema; a `null` resolution means no schema pre-check (type mapping still validates structure).
- **Lookup order**: per-call override on `Source` → explicit `withSchema()` binding → registered resolvers (in registration order, first non-null wins).
- The resolver receives the decoded document as well — this doubles as the **versioning hook**: `fn (string $class, mixed $doc) => $files->get("workflow-{$doc['version']}.json")`, and enables convention-based mapping (e.g. schema file derived from the class name) and plugin-provided schemas.
- Per-call `Source::…->withSchema(...)` remains as an **override** (ad-hoc mapping, tests, migrating a document against a different schema version).

### Source

```php
Source::json(string $json);          // parses internally — single place to attach line/offset info to errors
Source::array(array|\stdClass $data); // already-decoded input
Source::file(string $path);          // convenience

// Optional per-call schema override (vault is the default):
Source::json($raw)->withSchema(Schema|string $schemaOrUri);
```

Why `Source` exists at all:
- one `map()` signature for all input shapes (raw string, file, already-decoded data);
- **owning the parse step**: malformed-JSON errors land in the same `ErrorReport` (with byte offset/line), decode flags are controlled in one place (big integers as strings, depth limits), and a future single-pass engine (validate while parsing) can slot in without any API change;
- a carrier for per-call options (schema override; possibly a coercion override later).

**Strictness decision (2026-08-08): `map()`/`tryMap()` accept `Source` only.** No `string|array` union input, no auto-wrapping sugar — one explicit way in. Keeps signatures honest, static analysis exact, and avoids "is this string JSON or a type name?" ambiguity.

## Target classes: plain readonly PHP + attributes

```php
use JsonMine\Attribute\{Discriminator, Name, Format, Extras};

#[Discriminator('type')]                    // abstract/interface roots declare the discriminator field
abstract readonly class Field
{
    public function __construct(
        public string $name,
        public string $label,
        public bool $required = false,      // default value ⇒ optional key (≠ nullable)
    ) {}
}

final readonly class SelectField extends Field
{
    /** @param list<string> $options */    // generics via PHPDoc — enforced at runtime, understood by PHPStan
    public function __construct(
        string $name,
        string $label,
        public array $options,
        public ?string $default = null,     // nullable ≠ optional; both semantics honored
        bool $required = false,
    ) { parent::__construct($name, $label, $required); }
}

final readonly class FormDefinition
{
    /** @param list<Field> $fields */
    public function __construct(
        public string $id,
        public string $title,
        public array $fields,
        #[Format('date-time')] public ?\DateTimeImmutable $publishedAt = null,
        #[Extras] public array $extras = [],   // unknown keys land here → lossless round-trip
    ) {}
}
```

Attribute set for the MVP (small on purpose):

| Attribute | Where | Meaning |
|---|---|---|
| `#[Discriminator('type', map: [...])]` | union root (abstract class/interface) | which JSON field selects the variant + the closed-union value→class map (declared on the root because PHP cannot enumerate subclasses; open unions use the builder registry, which merges with and wins over the map) |
| `#[Name('json_key')]` | promoted parameter | JSON key ≠ property name |
| `#[Format('date-time')]` | parameter | disambiguates string conversions (dates, uuid, …) — reserved; the engine does not read it yet |
| `#[Extras]` | one array parameter | bag for unknown keys (round-trip) |

*(An earlier draft had `#[Variant('text')]` on subclasses — dropped as unimplementable: the mapper resolves the union root first and PHP cannot discover subclasses, so a subclass-side attribute would never be read.)*

Backed enums map automatically (value → case; error lists allowed values). `DateTimeImmutable` maps from ISO-8601 in Lax mode or with `#[Format]`.

## Discriminated unions: closed and open

```php
// Closed (compile-time known set): the root declares the map
#[Discriminator('type', map: ['text' => TextField::class, 'select' => SelectField::class])]
abstract readonly class Field {}

// Open (plugin territory — workflow nodes): runtime registry + fallback
$builder
    ->withVariant(Node::class, 'http', HttpNode::class)      // plugins add these at bootstrap
    ->withVariant(Node::class, 'delay', DelayNode::class)
    ->withVariantFallback(Node::class, GenericNode::class);  // omit ⇒ unknown variant = error (strict engines)
```

`GenericNode` receives the discriminator value + raw payload (via `#[Extras]`), so editors/migrations can pass unknown nodes through untouched.

## Errors

```php
final class MappingError {
    public function pointer(): string;   // absolute JSON Pointer: '/nodes/3/type'
    public function code(): string;      // machine-readable: 'schema.required', 'mapping.type', 'workflow.edge.dangling'
    public function message(): string;   // human-readable
    public function input(): mixed;      // offending value
}

final class ErrorReport implements \Countable, \IteratorAggregate { /* list<MappingError>, toArray(), toJson() */ }

final class MappingFailed extends \RuntimeException {
    public function report(): ErrorReport;
}
```

One report, three sources of entries (schema / types / `ObjectValidator`s), one format. Pretty-printing is a helper on top, never the storage format.

## Semantic validators

As designed in [03-custom-validation-design.md](03-custom-validation-design.md): `ObjectValidator` interface, registered per target class on the builder, invoked post-order on successfully hydrated nodes; root validators see the whole document via `$context->root()`.

## Stage 2 — implemented (2026-08-08); final shapes

```php
// Normalizer — objects → JSON, on TreeMapper (same metadata as hydration).
// Omits values hydration would restore anyway (defaults, missing nullables);
// #[Extras] merges back flat; union variants re-emit their discriminator.
$data = $mapper->normalize($workflow); // json_encode-ready

// SchemaGen — classes/type strings → JSON Schema draft 2020-12.
// required/additionalProperties mirror hydration semantics; unions → anyOf with
// const discriminators; classes → $defs/$ref (recursion-safe).
$schema = new SchemaGenerator()->generate(FormDefinition::class); // returns Schema

// Tree — typed access without classes (JsonNode, explicit API — no magic __get).
// Errors are MappingFailed with absolute JSON Pointers, like everywhere else.
$node = JsonNode::of(Source::json($dataJson));
$node->get('/customer/birthDate')->dateTime();
$node->get('/fields')->list();
// (schema-driven auto-conversion per `format` remains future scope)
```

## Test example living in the repo (not a package)

`examples/forms/` (or test fixtures): a minimal `FormDefinition` with 3–4 field types + a meta-schema + one `ObjectValidator` (unique field names) — exercises: closed unions, generics, optional/nullable, `#[Extras]`, schema pre-check, error aggregation. A second fixture `examples/workflow/` exercises: open unions + fallback, root-level graph validator. These double as integration tests and living documentation.

## Naming/API decisions (resolved 2026-08-08)

1. **Name**: stays `json-mine` for now; may be renamed once the project takes shape.
2. **`map()/tryMap()`** — chosen over `parse()/tryParse()`: parsing is only the first step, the result is a mapped object.
3. **`Source::json()` owns JSON parsing** — yes (byte-offset error info now, single-pass engine later).
4. **Cache: PSR-6** (`psr/cache` interfaces) — no own cache implementation; the user will plug in `symfony/cache` as the pool. The library only *consumes* a `CacheItemPoolInterface` and ships at most a trivial in-memory/null pool for tests. Keep the simple approach: cache usage itself stays minimal in the MVP (reflection metadata + compiled schemas).
