<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Discriminator;

#[Discriminator('type', map: [
    'text' => TextField::class,
    'select' => SelectField::class,
])]
abstract readonly class Field
{
    public function __construct(
        public string $name,
    ) {}
}
