<?php

declare(strict_types=1);

namespace JsonMine\Examples\Forms\Definition;

final readonly class FormDefinition
{
    /**
     * @param list<Field> $fields
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $fields,
    ) {}
}
