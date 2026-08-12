<?php

declare(strict_types=1);

namespace Ingot\Schema;

/**
 * Canonicalizes schema documents by content: two Schema instances carrying
 * identical documents resolve to the same \stdClass instance.
 *
 * Why: opis/json-schema caches parsed schemas by object identity. Code that
 * constructs a Schema per request (Schema::fromFile(...), version-resolving
 * closures) would otherwise force a full re-parse on every validation — and
 * grow the opis loader cache without bound.
 */
final class SchemaDocumentPool
{
    /** @var array<string, \stdClass|bool> */
    private array $byContent = [];

    public function canonical(Schema $schema): \stdClass|bool
    {
        $key = hash('xxh128', json_encode($schema->document, \JSON_THROW_ON_ERROR));

        return $this->byContent[$key] ??= $schema->document;
    }
}
