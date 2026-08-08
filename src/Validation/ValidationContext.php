<?php

declare(strict_types=1);

namespace JsonMine\Validation;

use JsonMine\Error\ErrorReport;
use JsonMine\Error\MappingError;
use JsonMine\JsonPointer;

/**
 * Collects semantic-validation errors for one validated object.
 *
 * Relative pointers passed to {@see addError()} are resolved against the
 * validated object's absolute path, so all errors land in the report with
 * absolute document locations.
 */
final class ValidationContext
{
    /** @var list<MappingError> */
    private array $errors = [];

    public function __construct(
        private readonly JsonPointer $path,
        private readonly mixed $root,
    ) {}

    /**
     * Absolute location of the validated object within the source document.
     */
    public function path(): JsonPointer
    {
        return $this->path;
    }

    /**
     * The hydrated document root — enables cross-node rules (referential
     * integrity, cycles, cross-section constraints).
     */
    public function root(): mixed
    {
        return $this->root;
    }

    /**
     * @param string $relativePointer JSON Pointer relative to the validated object ('' targets the object itself)
     * @param string $code machine-readable error code, e.g. "workflow.edge.dangling"
     */
    public function addError(string $relativePointer, string $code, string $message, mixed $invalidValue = null): void
    {
        $absolute = $this->path->join(JsonPointer::fromString($relativePointer));

        $this->errors[] = new MappingError($absolute, $code, $message, $invalidValue);
    }

    public function errors(): ErrorReport
    {
        return ErrorReport::of(...$this->errors);
    }
}
