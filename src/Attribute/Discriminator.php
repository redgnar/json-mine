<?php

declare(strict_types=1);

namespace Ingot\Attribute;

/**
 * Declares the JSON field that selects the concrete variant of a
 * discriminated union. Placed on the union root (abstract class or interface).
 *
 * Closed unions declare their variants in $map (PHP cannot enumerate
 * subclasses, so the root must list them). Open unions register variants at
 * runtime via the mapper builder; builder registrations are merged with $map
 * and win on conflict.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Discriminator
{
    /**
     * @param array<string, class-string> $map discriminator value → concrete class
     */
    public function __construct(
        public string $field,
        public array $map = [],
    ) {}
}
