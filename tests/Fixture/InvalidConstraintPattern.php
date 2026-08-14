<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Constraints;

final readonly class InvalidConstraintPattern
{
    public function __construct(
        #[Constraints(pattern: '[')]
        public string $code,
    ) {}
}
