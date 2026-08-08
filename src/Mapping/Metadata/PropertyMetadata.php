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
         * Whether the declaration carries a default value. Unlike constructor
         * parameters, the value itself is not needed: a missing key simply
         * leaves the declared default in place.
         */
        public bool $hasDefault,
        /** Marks the bag collecting JSON keys not mapped to any member. */
        public bool $isExtras,
    ) {}
}
