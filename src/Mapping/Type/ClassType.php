<?php

declare(strict_types=1);

namespace Ingot\Mapping\Type;

final readonly class ClassType implements TypeNode
{
    public function __construct(
        /** @var class-string */
        public string $class,
    ) {}
}
