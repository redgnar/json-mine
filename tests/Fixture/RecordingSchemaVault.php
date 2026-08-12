<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Schema\Schema;
use Ingot\Schema\SchemaVault;

/**
 * Spy vault recording which classes the mapper asks about.
 */
final class RecordingSchemaVault implements SchemaVault
{
    /** @var list<string> */
    public array $asked = [];

    public function has(string $class): bool
    {
        return false;
    }

    public function resolve(string $class, mixed $document): ?Schema
    {
        $this->asked[] = $class;

        return null;
    }
}
