<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

/**
 * Native nullability must survive a non-nullable docblock refinement.
 */
final readonly class NullableTags
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public ?array $tags,
    ) {}
}
