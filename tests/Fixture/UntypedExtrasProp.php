<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

use JsonMine\Attribute\Extras;

/**
 * #[Extras] on a docblock-mixed property is allowed.
 */
final class UntypedExtrasProp
{
    public string $id;

    /** @var mixed */
    #[Extras]
    public $bag = [];
}
