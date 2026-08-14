<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Constraints;

final readonly class StrayConstraintOnString
{
    public function __construct(
        #[Constraints(minItems: 1)]
        public string $name,
    ) {}
}
