<?php

declare(strict_types=1);

namespace Ingot\Examples\Forms\Definition;

final readonly class TextField extends Field
{
    public function __construct(
        string $name,
        string $label = '',
        bool $required = false,
        public ?int $maxLength = null,
        public ?string $pattern = null,
    ) {
        parent::__construct($name, $label, $required);
    }
}
