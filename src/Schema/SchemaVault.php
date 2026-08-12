<?php

declare(strict_types=1);

namespace Ingot\Schema;

/**
 * Answers which JSON Schema (if any) applies to a mapping target class.
 *
 * Consulted by the mapper before hydration; a `null` resolution means the
 * document is mapped without a schema pre-check (type mapping still validates
 * structure). Implementations may bind schemas statically, by convention, or
 * dynamically — `resolve()` receives the decoded document, which enables
 * version-dependent schema selection.
 */
interface SchemaVault
{
    /**
     * @param class-string $class
     */
    public function has(string $class): bool;

    /**
     * @param class-string $class
     */
    public function resolve(string $class, mixed $document): ?Schema;
}
