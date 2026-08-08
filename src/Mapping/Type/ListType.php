<?php

declare(strict_types=1);

namespace JsonMine\Mapping\Type;

/**
 * A JSON array mapped to a PHP list (sequential integer keys).
 */
final readonly class ListType implements TypeNode
{
    public function __construct(
        public TypeNode $item,
    ) {}
}
