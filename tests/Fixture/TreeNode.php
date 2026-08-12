<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

/**
 * Recursive structure — exercises $ref-based schema generation and
 * recursive hydration.
 */
final readonly class TreeNode
{
    /**
     * @param list<TreeNode> $children
     */
    public function __construct(
        public string $name,
        public array $children = [],
    ) {}
}
