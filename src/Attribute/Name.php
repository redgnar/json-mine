<?php

declare(strict_types=1);

namespace Ingot\Attribute;

/**
 * Maps a constructor parameter or a property to a JSON key with a different name.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Name
{
    public function __construct(
        public string $key,
    ) {}
}
