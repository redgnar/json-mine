<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Constraints;

final readonly class EmptyConstraints
{
    public function __construct(
        #[Constraints]
        public string $name,
    ) {}
}
