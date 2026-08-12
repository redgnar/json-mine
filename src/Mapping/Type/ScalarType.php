<?php

declare(strict_types=1);

namespace Ingot\Mapping\Type;

final readonly class ScalarType implements TypeNode
{
    public function __construct(
        public ScalarKind $kind,
    ) {}
}
