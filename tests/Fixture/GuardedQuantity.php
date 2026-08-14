<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Constraints;

final readonly class GuardedQuantity
{
    public function __construct(
        #[Constraints(minimum: 1)]
        public int $value,
    ) {
        if ($value < 1) {
            throw new \InvalidArgumentException('value must be positive');
        }
    }
}
