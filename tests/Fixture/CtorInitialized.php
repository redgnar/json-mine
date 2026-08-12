<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

/**
 * Conflict case: the constructor body initializes a readonly property that
 * is not a constructor parameter — a post-construction set must fail.
 */
final class CtorInitialized
{
    public readonly string $v;

    public function __construct()
    {
        $this->v = 'ctor';
    }
}
