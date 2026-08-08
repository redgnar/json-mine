<?php

declare(strict_types=1);

namespace JsonMine\Mapping\Type;

final readonly class NullableType implements TypeNode
{
    public function __construct(
        public TypeNode $inner,
    ) {}
}
