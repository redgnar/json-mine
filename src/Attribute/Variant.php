<?php

declare(strict_types=1);

namespace JsonMine\Attribute;

/**
 * Registers a concrete class as a closed-union variant: the discriminator
 * value that selects this class. Open unions (plugin-registered variants)
 * use the mapper builder registry instead.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Variant
{
    public function __construct(
        public string $value,
    ) {}
}
