<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

final readonly class CustomField extends Field
{
    public function __construct(
        string $name,
        public string $custom,
    ) {
        parent::__construct($name);
    }
}
