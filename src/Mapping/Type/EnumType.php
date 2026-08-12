<?php

declare(strict_types=1);

namespace Ingot\Mapping\Type;

final readonly class EnumType implements TypeNode
{
    public function __construct(
        /** @var class-string<\BackedEnum> */
        public string $enum,
    ) {}
}
