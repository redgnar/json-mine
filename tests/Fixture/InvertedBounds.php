<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Constraints;

final readonly class InvertedBounds
{
    public function __construct(
        #[Constraints(minimum: 10, maximum: 5)]
        public int $age,
    ) {}
}
