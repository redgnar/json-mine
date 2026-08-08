<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

/**
 * Exercises "parse, don't validate": the constructor guards an invariant.
 */
final readonly class Amount
{
    public function __construct(
        public int $cents,
    ) {
        if ($cents < 0) {
            throw new \InvalidArgumentException('Amount cannot be negative.');
        }
    }
}
