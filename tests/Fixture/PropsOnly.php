<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

/**
 * No constructor — everything hydrates through properties, including
 * private and readonly ones.
 */
final class PropsOnly
{
    public static int $instances;

    public readonly string $id;

    public string $name;

    private int $count;

    public ?string $note;

    public string $status = 'new';

    /** @var list<int> */
    public array $nums = [];

    public function count(): int
    {
        return $this->count;
    }
}
