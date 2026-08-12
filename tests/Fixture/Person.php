<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

final readonly class Person
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $name,
        public int $age,
        public Address $address,
        public array $tags = [],
        public ?string $nickname = null,
    ) {}
}
