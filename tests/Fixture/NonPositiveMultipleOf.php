<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Constraints;

final readonly class NonPositiveMultipleOf
{
    public function __construct(
        #[Constraints(multipleOf: 0)]
        public int $step,
    ) {}
}
