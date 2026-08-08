<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

use JsonMine\Attribute\Extras;
use JsonMine\Attribute\Name;

/**
 * Property-level #[Name] and #[Extras].
 */
final class PropsWithExtras
{
    public string $id;

    /** @var array<string, mixed> */
    #[Extras]
    public array $extras = [];

    #[Name('created_at')]
    public string $createdAt;

    /** @var array<string, mixed> */
    public array $meta = [];
}
