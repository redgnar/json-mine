<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Extras;

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
