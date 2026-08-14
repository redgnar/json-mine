<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Constraints;

final class ConstrainedProp
{
    #[Constraints(minimum: 1)]
    public int $rank = 1;

    #[Constraints(exclusiveMinimum: 0)]
    public float $score = 1.0;

    #[Constraints(multipleOf: 2)]
    public int $even = 0;

    #[Constraints(multipleOf: 1.0)]
    public float $whole = 0.0;
}
