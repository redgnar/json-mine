# Design note: custom validation via mapper plugins (ObjectValidator)

Date: 2026-08-08. Resolves open question 1 from [01-forms-context.md](01-forms-context.md) and the semantic-rules requirement from [02-process-context.md](02-process-context.md).

## Decision (from discussion)

Advanced validations — beyond JSON Schema and type mapping — are implemented in **separate classes** (not in the mapped classes themselves) that implement a dedicated interface. The mapper **recognizes the target class being mapped** and invokes the validators bound to it. Validators are helpers/plugins of the mapper.

Rationale:
- mapped classes stay pure data (readonly DTOs), no validation logic inside;
- validators can have dependencies (injected via DI), which data classes must never have;
- consumers (forms, workflows) ship their own validators without touching the core;
- one unified error report: schema errors + type errors + semantic errors, all with JSON Pointer paths.

## Interface sketch

```php
namespace Ingot\Validation;

/**
 * Validates a hydrated object beyond schema and type checks.
 *
 * Invoked by the mapper after the object (and its whole subtree) has been
 * successfully hydrated — the validator always receives a type-safe instance.
 *
 * @template T of object
 */
interface ObjectValidator
{
    /**
     * Reports problems to the context; never throws for data errors.
     *
     * @param T $object
     */
    public function validate(object $object, ValidationContext $context): void;
}
```

```php
namespace Ingot\Validation;

final class ValidationContext
{
    /** Absolute JSON Pointer of $object within the source document. */
    public function path(): JsonPointer;

    /** Document root object — enables cross-node rules (graph integrity etc.). */
    public function root(): object;

    /**
     * Report an error at a path relative to the validated object,
     * e.g. '/fields/3/label' or '' for the object itself.
     */
    public function addError(
        string $relativePointer,
        string $code,                 // machine-readable, e.g. 'workflow.edge.dangling'
        string $message,
        mixed $invalidValue = null,
    ): void;
}
```

## Binding validators to classes

Primary mechanism — explicit **registry on the mapper builder** (keeps data classes 100% clean, DI-friendly):

```php
$mapper = MapperBuilder::create()
    ->withValidator(FormDefinition::class, new UniqueFieldNamesValidator())
    ->withValidator(FormDefinition::class, new DateRangeRulesValidator())   // multiple per class
    ->withValidator(Workflow::class, new GraphIntegrityValidator($nodeRegistry))
    ->withValidatorFactory(Workflow::class, fn () => $container->get(AclValidator::class)) // lazy
    ->build();
```

Optional sugar — an attribute on the data class for zero-config cases (still points to an external class):

```php
#[ValidatedBy(GraphIntegrityValidator::class)]
final readonly class Workflow { /* ... */ }
```

The attribute is resolved through the same registry (so DI can supply the instance); explicit registration always wins. Whether we ship the attribute in the MVP is open — the registry alone is sufficient.

## Execution model

1. Mapper hydrates the tree (schema pre-check → type mapping), collecting errors as usual.
2. **Post-order traversal**: once a node and its subtree hydrate successfully, validators bound to that node's class run. Nodes that failed hydration skip their validators (no half-built input).
3. Root-level validators run last and see the complete document via `$context->root()` — this is where cross-node rules live (referential integrity, cycles, cross-field date rules spanning sections).
4. All errors — schema, type, semantic — end up in **one aggregated report**, each with an absolute JSON Pointer (context resolves relative pointers against the node's path) and a machine-readable code. `map()` throws the aggregate; `tryMap()` returns it.

## Scope boundary: conditional/business rules are plugin territory

Decision (from discussion): **conditional rules (if/then-style, cross-field business rules) are explicitly out of the core's scope.** The library does not model, translate, or interpret them — it only provides the extension point (`ObjectValidator` + registry + unified error report). Consumers/plugins decide how to express and evaluate such rules:

- **Definition-level** rules (unique field names, graph integrity) — validators bound to definition classes, shipped by consumer packages.
- **Data-level** conditional/business rules — entirely a consumer/plugin concern; a forms plugin may keep its own rule vocabulary and evaluate it in its validators. Whether (and how far) any of it gets translated into the derived JSON Schema is that plugin's decision, not the core's.

The core stays agnostic: schema validation (delegated), type mapping, and the validator extension point. Nothing conditional-rule-shaped in the core API.

## Consequences for the MVP cut

- `ObjectValidator` + `ValidationContext` + registry + post-order invocation enter **stage 1** (the core is small; the extension point is the deliverable).
- The unified error report format must accommodate semantic error codes from day 1 (already planned).
- `#[ValidatedBy]` attribute: stage 2 (sugar).
