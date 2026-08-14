<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Constraints;

final readonly class StrayConstraintOnMap
{
    /**
     * @param array<string, int> $scores
     */
    public function __construct(
        #[Constraints(minItems: 1)]
        public array $scores,
    ) {}
}
