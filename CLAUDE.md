# json-mine — agent guide

A PHP library for typed handling of JSON definitions: read JSON → validate against JSON Schema (delegated) → get a typed tree of PHP objects. Design docs live in `.claude/plan/` — read `00-mvp-analysis.md` (decisions in §7) and `04-core-api-sketch.md` before touching the core API.

## Language

All code, comments, documentation, commit messages: **English**. Conversation with the user: Polish.

## Architecture ground rules

- **Own mapper engine** — do not introduce a dependency on Valinor/Symfony Serializer or similar mappers.
- **Schema validation is delegated** (`opis/json-schema` behind our `SchemaValidator` interface) — never implement JSON Schema keywords ourselves.
- **Core stays generic**: nothing form-, workflow-, or domain-specific in `src/`. Conditional/business rules belong to consumer plugins via the `ObjectValidator` extension point (`.claude/plan/03-custom-validation-design.md`).
- **Strict by default**: `Coercion::Strict` is the default mode; `map()`/`tryMap()` accept `Source` only (no `string|array` unions in public entry points).
- **One error report**: schema, type-mapping, and semantic errors share one format — absolute JSON Pointer + machine-readable code + input value.
- Target classes are plain DTOs. Hydration is hybrid: constructor parameters first (invariants always run), then members the constructor does not cover are set via reflection (public/private/uninitialized-readonly properties); a property whose name matches a constructor parameter is constructor-owned. Optional (default value) ≠ nullable (`?Type`) — honor both semantics.
- PHP >= 8.4; PSR-6 for caching (consume `CacheItemPoolInterface`, never implement a real cache here).

## Testing (PHPUnit)

- **Every functionality gets a test.** New public behavior without a covering test does not ship.
- Test bodies follow the **GIVEN / WHEN / THEN** template, marked with comments:

```php
public function testMapsDiscriminatedUnionVariantByTypeField(): void
{
    // GIVEN
    $mapper = MapperBuilder::create()->build();
    $source = Source::json('{"type": "text", "name": "email"}');

    // WHEN
    $field = $mapper->map(Field::class, $source);

    // THEN
    self::assertInstanceOf(TextField::class, $field);
    self::assertSame('email', $field->name);
}
```

- Test method names describe behavior (`testRejectsUnknownVariantWhenNoFallbackRegistered`), not implementation.
- One behavior per test; error-path tests assert the JSON Pointer and error code, not just the exception class.
- Tests mirror `src/` structure under `tests/` (`src/Mapping/Foo.php` → `tests/Mapping/FooTest.php`).

## Quality gates (all must pass before any commit)

**Hard rule: any code produced by the agent must pass every validation — php-cs-fixer, PHPStan, and tests. Run `make ci` before declaring any task done; a task with a red `make ci` is not finished.**

Local PHP is 8.1 — **all tools (tests included) run inside the pinned Docker image defined in `docker/Dockerfile`** (`php:8.4-cli-alpine` + pcov + composer). Never run them on the host PHP; the `make` targets wrap the container invocation (rebuild with `make image` after changing the Dockerfile):

| Command | What it does |
|---|---|
| `make install` | `composer install` (Docker, PHP 8.4) |
| `make test` | PHPUnit |
| `make stan` | PHPStan, level `max` + strict rules — zero errors, no baseline |
| `make cs` | php-cs-fixer dry-run (check only) |
| `make cs-fix` | php-cs-fixer apply |
| `make audit` | `composer audit` — known CVEs in dependencies |
| `make deps` | composer-require-checker + composer-unused (dependency hygiene) |
| `make mutation` | Infection mutation testing (`infection.json5`, minMsi 90 / minCoveredMsi 100) |
| `make bench` | phpbench performance benchmarks (informational, not a CI gate) |
| `make ci` | everything CI runs: validate + cs + stan + test + audit + deps + mutation |

IDE: `docker-compose.yml` defines the `php` service for PhpStorm (CLI Interpreter → From Docker Compose → `php`; PHPUnit by Remote Interpreter with `/app/vendor/autoload.php`) — tests are runnable from the IDE through the same pinned image.

Rules:
- PHPStan level `max` with `phpstan-strict-rules`; do not add a baseline; do not silence errors with `@phpstan-ignore` unless truly unavoidable (then explain why in the ignore comment).
- Formatting is php-cs-fixer's job (`.php-cs-fixer.dist.php`, PER-CS + declare_strict_types); never hand-format against it.
- Every PHP file starts with `declare(strict_types=1);` (enforced by the fixer).
- CI (GitHub Actions, `.github/workflows/ci.yml`) runs the same gates on PHP 8.4 and 8.5 — keep `make ci` and the workflow in sync when adding tools.

## Repo conventions

- **Never commit or push on your own initiative** — finish with a green `make ci`, report the changes, and leave git operations to the user unless they explicitly ask in the current conversation.
- **Never add `Co-Authored-By` (or similar) trailers** to commit messages.
- The remote is GitHub (`gh` CLI is fine for this repo).
- Do not commit `vendor/`, `composer.lock`, caches (see `.gitignore`).
- Performance matters: hot-path changes (hydration loop, schema pre-check) should come with a phpbench comparison once benchmarks exist.
