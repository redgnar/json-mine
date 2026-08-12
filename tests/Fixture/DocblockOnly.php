<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

/**
 * The parameter type comes exclusively from the docblock (no native type).
 */
final class DocblockOnly
{
    /** @var list<int> */
    public array $nums;

    /**
     * @param list<int> $nums
     */
    public function __construct($nums)
    {
        $this->nums = $nums;
    }
}
