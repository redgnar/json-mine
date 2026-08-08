<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

/**
 * Invalid configuration: variadic constructor parameters are not supported.
 */
final class Variadic
{
    /** @var list<string> */
    public array $items;

    public function __construct(string ...$items)
    {
        $this->items = array_values($items);
    }
}
