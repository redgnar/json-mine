<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Constraints;

final readonly class BadConstraintGroup
{
    public function __construct(
        #[Constraints(minLength: 3)]
        public int $count,
    ) {}
}
