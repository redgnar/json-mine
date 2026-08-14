<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Constraints;

final readonly class TildePattern
{
    public function __construct(
        #[Constraints(pattern: 'a~b')]
        public string $code,
    ) {}
}
