<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * In-memory PSR-6 pool with spy counters for cache-behavior assertions.
 */
final class ArrayCachePool implements CacheItemPoolInterface
{
    /** @var array<string, mixed> */
    public array $storage = [];

    public int $hits = 0;

    public int $misses = 0;

    public int $saves = 0;

    public ?string $lastSavedKey = null;

    public function getItem(string $key): CacheItemInterface
    {
        if (\array_key_exists($key, $this->storage)) {
            ++$this->hits;

            return new ArrayCacheItem($key, $this->storage[$key], true);
        }

        ++$this->misses;

        return new ArrayCacheItem($key);
    }

    /**
     * @param array<string> $keys
     *
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }

    public function hasItem(string $key): bool
    {
        return \array_key_exists($key, $this->storage);
    }

    public function clear(): bool
    {
        $this->storage = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->storage[$key]);

        return true;
    }

    /**
     * @param array<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->storage[$key]);
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        $this->storage[$item->getKey()] = $item->get();
        $this->lastSavedKey = $item->getKey();
        ++$this->saves;

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }
}
