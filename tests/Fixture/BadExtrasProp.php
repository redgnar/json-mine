<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

use JsonMine\Attribute\Extras;

/**
 * Invalid configuration: #[Extras] must be an array property.
 */
final class BadExtrasProp
{
    #[Extras]
    public string $extras = '';
}
