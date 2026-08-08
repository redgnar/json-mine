<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

use JsonMine\Attribute\Extras;

/**
 * The #[Extras] bag is not the last constructor parameter.
 */
final readonly class WithExtrasFirst
{
    /**
     * @param array<string, mixed> $extras
     */
    public function __construct(
        #[Extras]
        public array $extras,
        public string $id,
    ) {}
}
