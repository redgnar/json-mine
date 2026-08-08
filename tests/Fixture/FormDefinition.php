<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

final readonly class FormDefinition
{
    /**
     * @param list<Field> $fields
     */
    public function __construct(
        public string $id,
        public array $fields,
    ) {}
}
