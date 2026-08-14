<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Constraints;

final readonly class Money
{
    public function __construct(
        #[Constraints(minLength: 3, maxLength: 3, pattern: '^[A-Z]{3}$')]
        public string $currency,
        #[Constraints(minimum: 0, maximum: 1000000, multipleOf: 0.01)]
        public float $amount,
        #[Constraints(exclusiveMinimum: 0, exclusiveMaximum: 100, multipleOf: 5)]
        public int $quantity = 5,
    ) {}
}
