<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

/**
 * A failed member must prevent the property phase from running: setting $v
 * post-construction would raise a conflict error that must never surface
 * when the real problem is elsewhere.
 */
final class GuardedProps
{
    public readonly string $v;

    public string $req;

    public function __construct()
    {
        $this->v = 'ctor';
    }
}
