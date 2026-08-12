<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Extras;
use Ingot\Attribute\Name;

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
