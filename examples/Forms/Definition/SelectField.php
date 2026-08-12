<?php

declare(strict_types=1);

namespace JsonMine\Examples\Forms\Definition;

final readonly class SelectField extends Field
{
    /**
     * @param list<string> $options
     */
    public function __construct(
        string $name,
        public array $options,
        string $label = '',
        bool $required = false,
    ) {
        parent::__construct($name, $label, $required);
    }
}
