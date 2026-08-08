<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

final readonly class Address
{
    public function __construct(
        public string $street,
        public string $city,
    ) {}
}
