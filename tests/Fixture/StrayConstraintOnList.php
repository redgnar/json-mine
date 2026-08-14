<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Constraints;

final readonly class StrayConstraintOnList
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        #[Constraints(minLength: 1)]
        public array $tags,
    ) {}
}
