<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

final readonly class TextField extends Field
{
    public function __construct(
        string $name,
        public ?int $maxLength = null,
    ) {
        parent::__construct($name);
    }
}
