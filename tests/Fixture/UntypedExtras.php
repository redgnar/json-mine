<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Extras;

/**
 * #[Extras] on an untyped parameter (mixed) is allowed.
 */
final class UntypedExtras
{
    public mixed $bag;

    /**
     * @param mixed $bag
     */
    public function __construct(
        #[Extras]
        $bag = [],
    ) {
        $this->bag = $bag;
    }
}
