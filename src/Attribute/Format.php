<?php

declare(strict_types=1);

namespace Ingot\Attribute;

/**
 * Disambiguates string conversions for a constructor parameter,
 * e.g. 'date-time' for DateTimeImmutable, 'uuid', 'uri'.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Format
{
    public function __construct(
        public string $format,
    ) {}
}
