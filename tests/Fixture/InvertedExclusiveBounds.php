<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Constraints;

final readonly class InvertedExclusiveBounds
{
    public function __construct(
        #[Constraints(exclusiveMinimum: 5, exclusiveMaximum: 5)]
        public float $rate,
    ) {}
}
