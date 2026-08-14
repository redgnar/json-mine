<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Constraints;

final readonly class UniqueBag
{
    /**
     * @param list<mixed> $items
     */
    public function __construct(
        #[Constraints(minItems: 0, uniqueItems: true)]
        public array $items,
    ) {}
}
