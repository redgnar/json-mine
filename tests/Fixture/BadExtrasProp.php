<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Extras;

/**
 * Invalid configuration: #[Extras] must be an array property.
 */
final class BadExtrasProp
{
    #[Extras]
    public string $extras = '';
}
