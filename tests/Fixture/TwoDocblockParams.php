<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

final readonly class TwoDocblockParams
{
    /**
     * @param list<string> $labels
     * @param list<int> $counts
     */
    public function __construct(
        public array $labels,
        public array $counts,
    ) {}
}
