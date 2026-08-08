<?php

declare(strict_types=1);

namespace JsonMine\Attribute;

/**
 * Marks one array parameter as the bag for JSON keys not mapped to any other
 * parameter. Enables lossless round-trips: unknown fields and vendor
 * extensions survive load → edit → save.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Extras {}
