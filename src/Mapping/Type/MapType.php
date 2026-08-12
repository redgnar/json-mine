<?php

declare(strict_types=1);

namespace Ingot\Mapping\Type;

/**
 * A JSON object mapped to a PHP associative array.
 *
 * MVP note: the key type of `array<K, V>` is accepted but not enforced —
 * JSON object keys are always strings.
 */
final readonly class MapType implements TypeNode
{
    public function __construct(
        public TypeNode $value,
    ) {}
}
