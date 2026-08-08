<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

final readonly class SelectField extends Field
{
    /**
     * @param list<string> $options
     */
    public function __construct(
        string $name,
        public array $options,
    ) {
        parent::__construct($name);
    }
}
