<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Format;

final readonly class BadFormatTarget
{
    public function __construct(
        #[Format('uuid')]
        public int $id,
    ) {}
}
