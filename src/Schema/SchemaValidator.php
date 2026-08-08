<?php

declare(strict_types=1);

namespace JsonMine\Schema;

use JsonMine\Error\ErrorReport;

/**
 * Validates a decoded document against a JSON Schema.
 *
 * This is the delegation boundary: implementations wrap an existing validator
 * (opis/json-schema today, native JsonSchema from PHP 8.6 later) and translate
 * its findings into the unified error format. The library never implements
 * JSON Schema keywords itself.
 */
interface SchemaValidator
{
    /**
     * An empty report means the document is valid against the schema.
     */
    public function validate(mixed $document, Schema $schema): ErrorReport;
}
