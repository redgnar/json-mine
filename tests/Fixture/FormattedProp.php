<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Format;

final class FormattedProp
{
    #[Format('uuid')]
    public string $id;
}
