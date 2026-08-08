<?php

declare(strict_types=1);

namespace JsonMine\Attribute;

/**
 * Marks one array parameter (or property) as the bag for JSON keys not mapped
 * to any other member. Enables lossless round-trips: unknown fields and
 * vendor extensions survive load → edit → save.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Extras {}
