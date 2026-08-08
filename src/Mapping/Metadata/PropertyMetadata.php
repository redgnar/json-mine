<?php

declare(strict_types=1);

namespace JsonMine\Mapping\Metadata;

use JsonMine\Mapping\Type\TypeNode;

/**
 * How one non-constructor property maps from JSON.
 *
 * Covers class members not reachable through the constructor: they are set
 * via reflection after construction (public, private, and uninitialized
 * readonly properties alike).
 */
final readonly class PropertyMetadata
{
    public function __construct(
        public string $name,
        /** The JSON key this property reads from (#[Name] or the property name). */
        public string $jsonKey,
        public TypeNode $type,
        /**
         * Whether the declaration carries a default value. Hydration only
         * needs the flag (a missing key leaves the declared default in
         * place); normalization compares against $default to omit values
         * that hydration would restore anyway.
         */
        public bool $hasDefault,
        public mixed $default,
        /** Marks the bag collecting JSON keys not mapped to any member. */
        public bool $isExtras,
    ) {}
}
