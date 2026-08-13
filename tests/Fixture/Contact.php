<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Format;

final readonly class Contact
{
    public function __construct(
        #[Format('uuid')]
        public string $id,
        #[Format('email')]
        public ?string $email = null,
        #[Format('uri')]
        public ?string $website = null,
        #[Format('date')]
        public ?string $birthday = null,
        #[Format('date-time')]
        public ?string $lastSeen = null,
    ) {}
}
