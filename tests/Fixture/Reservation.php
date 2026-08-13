<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Format;

final readonly class Reservation
{
    public function __construct(
        #[Format('date')]
        public \DateTimeImmutable $day,
        #[Format('date')]
        public ?\DateTimeImmutable $until = null,
    ) {}
}
