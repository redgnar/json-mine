<?php

declare(strict_types=1);

namespace JsonMine\Error;

use JsonMine\JsonPointer;

/**
 * A single problem found while processing a document.
 *
 * All three validation surfaces (schema validation, type mapping, semantic
 * validators) produce errors in this one format.
 */
final readonly class MappingError
{
    public function __construct(
        /** Absolute location of the problem within the source document. */
        public JsonPointer $pointer,
        /** Machine-readable code, e.g. "schema.required", "mapping.type", "workflow.edge.dangling". */
        public string $code,
        /** Human-readable description. */
        public string $message,
        /** The offending input value, when available. */
        public mixed $input = null,
    ) {}
}
