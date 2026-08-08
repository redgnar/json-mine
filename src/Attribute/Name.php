<?php

declare(strict_types=1);

namespace JsonMine\Attribute;

/**
 * Maps a constructor parameter to a JSON key with a different name.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Name
{
    public function __construct(
        public string $key,
    ) {}
}
