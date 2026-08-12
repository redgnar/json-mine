<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;
use Ingot\Schema\Schema;
use Ingot\Schema\SchemaValidator;

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
