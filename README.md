# ingot

[![CI](https://github.com/redgnar/ingot/actions/workflows/ci.yml/badge.svg)](https://github.com/redgnar/ingot/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.4-777BB4?logo=php&logoColor=white)](composer.json)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max%20%2B%20strict-brightgreen)](phpstan.neon.dist)
[![Mutation testing](https://img.shields.io/badge/Infection-covered%20MSI%20100%25-blue)](infection.json5)
[![License](https://img.shields.io/badge/license-MIT-lightgrey)](composer.json)

Typed handling of JSON definitions for PHP 8.4+: read a JSON document, optionally
validate it against a JSON Schema, and work with a fully typed PHP object tree —
no manual casting, no hand-written mapping code.

One pass, one error format: schema violations, type-mapping problems, and
semantic rule failures all land in a single report, each entry carrying an
absolute JSON Pointer, a machine-readable code, and the offending value.

## Quick start

```php
use Ingot\MapperBuilder;
use Ingot\Source;

final readonly class Address
{
    public function __construct(
        public string $street,
        public string $city,
    ) {}
}

final readonly class Person
{
    /** @param list<string> $tags */
    public function __construct(
        public string $name,
        public int $age,
        public Address $address,
        public array $tags = [],
        public ?string $nickname = null,
    ) {}
}

$mapper = MapperBuilder::create()->build();

// Throwing style — returns Person or throws MappingFailed with the full report
$person = $mapper->map(Person::class, Source::json($json));

// Result style — never throws for data errors
$result = $mapper->tryMap(Person::class, Source::json($json));

if (!$result->isSuccess()) {
    foreach ($result->errors() as $error) {
        // $error->pointer  '/address/city'
        // $error->code     'mapping.type'
        // $error->message  'Expected string, got int.'
    }
}

// And back: typed values → json_encode-ready data (lossless round-trip)
$data = $mapper->normalize($person);
```

## Working with JSON Schema

Bind a schema to a class once — every `map()`/`tryMap()` of that class then
validates the document first, and schema violations land in the same report
as mapping errors (JSON Pointer + `schema.<keyword>` code):

```php
use Ingot\Schema\Schema;

$mapper = MapperBuilder::create()
    ->withSchema(Person::class, Schema::fromFile(__DIR__ . '/schemas/person-1.0.json'))
    ->build();

$result = $mapper->tryMap(Person::class, Source::json('{"name": "Ada", "age": -1, "address": {}}'));

foreach ($result->errors() as $error) {
    // $error->pointer  '/age'
    // $error->code     'schema.minimum'
    // $error->message  'Number must be greater than or equal to 0'
}
```

Schemas can also be resolved dynamically — by convention, from plugins, or by
document content (the versioning hook) — and overridden per call:

```php
$mapper = MapperBuilder::create()
    // decide per class (null = no schema, mapping proceeds with type checks only)
    ->withSchemaResolver(
        fn (string $class, mixed $document): ?Schema
            => $document instanceof \stdClass && isset($document->version)
                ? Schema::fromFile(__DIR__ . "/schemas/person-{$document->version}.json")
                : null,
    )
    ->build();

// a per-call override always wins over registered schemas:
$mapper->tryMap(Person::class, Source::json($json)->withSchema(Schema::fromDocument(true)));
```

The reverse direction — generate a JSON Schema (draft 2020-12) from the same
metadata the mapper reads, so the contract cannot drift from behavior. The
generated schema validates documents produced by `normalize()` and can be
shipped to other consumers (e.g. frontend validation with Ajv):

```php
use Ingot\SchemaGen\SchemaGenerator;

$schema = new SchemaGenerator()->generate(Person::class);

file_put_contents(
    'person.schema.json',
    json_encode($schema->document, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
);
```

For documents whose shape exists only at runtime (no classes to map to),
validate against a schema and read values through `JsonNode`:

```php
use Ingot\Schema\OpisSchemaValidator;
use Ingot\Tree\JsonNode;

$report = new OpisSchemaValidator()->validate(json_decode($raw), $schema);

if ($report->isEmpty()) {
    $node = JsonNode::of(Source::json($raw));
    $node->get('/customer/birthDate')->dateTime();
    $node->get('/items')->list();
}
```

## Features

- **Hybrid hydration** — constructor parameters first (invariants always run),
  then members the constructor does not cover are set via reflection: public,
  private, and uninitialized readonly properties alike.
- **Rich types** — nested objects, `list<T>` / `array<K, V>` via PHPDoc, backed
  enums, `DateTimeImmutable`, nullable vs optional (both semantics honored),
  strict by default with an opt-in lax coercion table.
- **Discriminated unions** — closed maps declared on the union root
  (`#[Discriminator('type', map: [...])]`), open plugin-registered variants
  (`withVariant()`), and a fallback for unknown variants (`withVariantFallback()`)
  that preserves the raw payload.
- **JSON Schema validation** — bind schemas to classes (`withSchema()`, dynamic
  resolvers, versioning by document content); validation is delegated to
  [opis/json-schema](https://github.com/opis/json-schema) and gates hydration.
- **Semantic validators** — plug rule classes into the mapper per target class
  (`withValidator()`); they receive fully hydrated, type-safe objects and report
  into the same error format.
- **Lossless round-trips** — `#[Extras]` collects unknown keys and `normalize()`
  merges them back flat; union variants re-emit their discriminator.
- **Schema generation** — `SchemaGenerator` emits JSON Schema draft 2020-12 from
  the same metadata the mapper reads, so the schema cannot drift from behavior.
- **Typed access without classes** — `JsonNode` navigates documents whose shape
  exists only at runtime, with the same pointer-carrying errors.
- **PSR-6 caching** — `withCache($pool)` shares mapper metadata across requests
  (bring your own pool, e.g. symfony/cache).

## Complete example

[`examples/Forms`](examples/Forms) runs the whole pipeline end to end: a form
definition validated by a meta-schema and a semantic rule, hydrated into a
discriminated union (with a fallback preserving unknown plugin fields), a
**data schema derived from the definition** (shippable to the frontend),
submission validation, typed value access via `JsonNode`, and a lossless
save. [`tests/Examples/FormsExampleTest.php`](tests/Examples/FormsExampleTest.php)
keeps it working.

## Attributes

| Attribute | Placement | Meaning |
|---|---|---|
| `#[Discriminator('type', map: [...])]` | union root | discriminator field + closed variant map |
| `#[Name('json_key')]` | parameter / property | JSON key differs from the member name |
| `#[Extras]` | one array member | bag for unknown keys (round-trip) |
| `#[Format('date-time')]` | parameter / property | reserved for string-conversion hints |

## Development

Everything runs inside a pinned Docker image (`docker/Dockerfile`); local PHP is
not used. `docker-compose.yml` exposes the same image to PhpStorm.

```
make install   # composer install
make test      # PHPUnit
make ci        # the full pipeline: validate, cs, stan, tests, audit, deps, mutation
make bench     # phpbench benchmarks
```

Quality gates: PHPStan level max with strict rules, php-cs-fixer (PER-CS),
Infection mutation testing (covered-code MSI 100%), composer audit and
dependency-hygiene checks. CI runs the same gates on PHP 8.4 and 8.5.

## Status

Pre-1.0, under active development. The public API may still change.
