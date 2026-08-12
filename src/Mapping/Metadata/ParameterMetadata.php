<?php

declare(strict_types=1);

namespace Ingot\Mapping\Metadata;

use Ingot\Mapping\Type\TypeNode;

/**
 * How one constructor parameter maps from JSON.
 */
final readonly class ParameterMetadata
{
    public function __construct(
        public string $name,
        /** The JSON key this parameter reads from (#[Name] or the parameter name). */
        public string $jsonKey,
        public TypeNode $type,
        public bool $hasDefault,
        public mixed $default,
        /** Marks the bag collecting JSON keys not mapped to any parameter. */
        public bool $isExtras,
    ) {}
}
