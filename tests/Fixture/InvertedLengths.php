<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Constraints;

final readonly class InvertedLengths
{
    public function __construct(
        #[Constraints(minLength: 5, maxLength: 2)]
        public string $name,
    ) {}
}
