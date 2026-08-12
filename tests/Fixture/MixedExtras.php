<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Extras;

/**
 * #[Extras] on a native `mixed` parameter is allowed.
 */
final readonly class MixedExtras
{
    public function __construct(
        public string $id,
        #[Extras]
        public mixed $bag = [],
    ) {}
}
