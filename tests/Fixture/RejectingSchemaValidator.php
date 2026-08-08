<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

use JsonMine\Error\ErrorReport;
use JsonMine\Error\MappingError;
use JsonMine\JsonPointer;
use JsonMine\Schema\Schema;
use JsonMine\Schema\SchemaValidator;

/**
 * Stub backend rejecting every document with a recognizable code.
 */
final class RejectingSchemaValidator implements SchemaValidator
{
    public function validate(mixed $document, Schema $schema): ErrorReport
    {
        return ErrorReport::of(new MappingError(JsonPointer::root(), 'stub.rejected', 'Rejected by the stub backend.'));
    }
}
