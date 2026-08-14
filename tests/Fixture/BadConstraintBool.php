<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Constraints;

final readonly class BadConstraintBool
{
    public function __construct(
        #[Constraints(minimum: 1)]
        public bool $active,
    ) {}
}
