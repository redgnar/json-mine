<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

/**
 * Hybrid hydration: the constructor covers $id, while $slug (readonly, not a
 * constructor parameter) is set after construction.
 */
final class HybridSlug
{
    public readonly string $slug;

    public function __construct(
        public readonly string $id,
    ) {}
}
