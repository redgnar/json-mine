<?php

declare(strict_types=1);

namespace JsonMine\Attribute;

/**
 * Disambiguates string conversions for a constructor parameter,
 * e.g. 'date-time' for DateTimeImmutable, 'uuid', 'uri'.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Format
{
    public function __construct(
        public string $format,
    ) {}
}
