<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Format;

final readonly class UnknownFormat
{
    public function __construct(
        #[Format('hostname')]
        public string $server,
    ) {}
}
