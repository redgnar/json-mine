<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Format;
use Ingot\Attribute\Name;

final readonly class Event
{
    public function __construct(
        public string $title,
        #[Name('created_at')]
        #[Format('date-time')]
        public \DateTimeImmutable $createdAt,
        public Color $color = Color::Red,
    ) {}
}
