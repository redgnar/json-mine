<?php

declare(strict_types=1);

namespace JsonMine\Attribute;

/**
 * Declares the JSON field that selects the concrete variant of a
 * discriminated union. Placed on the union root (abstract class or interface).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Discriminator
{
    public function __construct(
        public string $field,
    ) {}
}
