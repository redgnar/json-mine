<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

use JsonMine\Attribute\Discriminator;

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
