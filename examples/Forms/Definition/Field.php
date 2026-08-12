<?php

declare(strict_types=1);

namespace Ingot\Examples\Forms\Definition;

use Ingot\Attribute\Discriminator;

/**
 * A form field — the discriminated-union root of the definition model.
 * Closed variants live in the map; plugins may register more on the builder,
 * and unknown types fall back to {@see GenericField}.
 */
#[Discriminator('type', map: [
    'text' => TextField::class,
    'select' => SelectField::class,
    'number' => NumberField::class,
])]
abstract readonly class Field
{
    public function __construct(
        public string $name,
        public string $label = '',
        public bool $required = false,
    ) {}
}
