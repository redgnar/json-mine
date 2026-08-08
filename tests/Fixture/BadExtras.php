<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

use JsonMine\Attribute\Extras;

/**
 * Invalid configuration: #[Extras] must be an array parameter.
 */
final readonly class BadExtras
{
    public function __construct(
        #[Extras]
        public string $extras,
    ) {}
}
