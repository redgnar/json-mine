<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

/**
 * Invalid configuration: native union types are not supported.
 */
final readonly class UnionNative
{
    public function __construct(
        public string|int $id,
    ) {}
}
