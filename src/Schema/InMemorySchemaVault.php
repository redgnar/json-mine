<?php

declare(strict_types=1);

namespace Ingot\Schema;

/**
 * Schema vault backed by in-memory registrations.
 *
 * Two registration styles:
 * - explicit bindings: a Schema (or a document-aware factory) per class,
 * - dynamic resolvers: functions consulted for classes without a binding
 *   (conventions, plugin discovery, version-dependent selection).
 *
 * Resolution order: explicit binding first, then resolvers in registration
 * order — the first non-null answer wins.
 */
final class InMemorySchemaVault implements SchemaVault
{
    /** @var array<class-string, Schema|\Closure(mixed): ?Schema> */
    private array $bindings = [];

    /** @var list<\Closure(class-string, mixed): ?Schema> */
    private array $resolvers = [];

    /**
     * @param class-string $class
     * @param Schema|\Closure(mixed): ?Schema $schema a fixed schema, or a factory
     *        receiving the decoded document (the versioning hook)
     */
    public function bind(string $class, Schema|\Closure $schema): self
    {
        $this->bindings[$class] = $schema;

        return $this;
    }

    /**
     * @param \Closure(class-string, mixed): ?Schema $resolver
     */
    public function addResolver(\Closure $resolver): self
    {
        $this->resolvers[] = $resolver;

        return $this;
    }

    /**
     * Answers for explicit bindings only — dynamic resolvers cannot decide
     * without seeing a document.
     */
    public function has(string $class): bool
    {
        return isset($this->bindings[$class]);
    }

    public function resolve(string $class, mixed $document): ?Schema
    {
        $binding = $this->bindings[$class] ?? null;

        if ($binding instanceof Schema) {
            return $binding;
        }

        if ($binding instanceof \Closure) {
            return $binding($document);
        }

        foreach ($this->resolvers as $resolver) {
            $schema = $resolver($class, $document);

            if ($schema !== null) {
                return $schema;
            }
        }

        return null;
    }
}
