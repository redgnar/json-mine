<?php

declare(strict_types=1);

namespace JsonMine\Tests\Mapping\Metadata;

use JsonMine\Mapping\Metadata\MetadataFactory;
use JsonMine\Mapping\Type\TypeParser;
use JsonMine\Tests\Fixture\Address;
use JsonMine\Tests\Fixture\ArrayCachePool;
use JsonMine\Tests\Fixture\BadExtras;
use JsonMine\Tests\Fixture\Person;
use JsonMine\Tests\Fixture\UnionNative;
use JsonMine\Tests\Fixture\Variadic;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetadataFactory::class)]
final class MetadataFactoryTest extends TestCase
{
    public function testMetadataIsBuiltOnceAndCached(): void
    {
        // GIVEN
        $factory = new MetadataFactory();

        // WHEN
        $first = $factory->for(Address::class);
        $second = $factory->for(Address::class);

        // THEN
        self::assertSame($first, $second);
    }

    public function testStoresBuiltMetadataInThePsr6Pool(): void
    {
        // GIVEN
        $pool = new ArrayCachePool();
        $factory = new MetadataFactory(new TypeParser(), $pool);

        // WHEN
        $factory->for(Address::class);

        // THEN one miss, one save
        self::assertSame(1, $pool->misses);
        self::assertSame(1, $pool->saves);
        self::assertSame(0, $pool->hits);
    }

    public function testAnotherFactoryInstanceReusesPooledMetadata(): void
    {
        // GIVEN a pool warmed by a previous factory (≈ previous request)
        $pool = new ArrayCachePool();
        $warm = new MetadataFactory(new TypeParser(), $pool);
        $original = $warm->for(Address::class);

        // WHEN a fresh factory uses the same pool
        $factory = new MetadataFactory(new TypeParser(), $pool);
        $metadata = $factory->for(Address::class);

        // THEN it did not rebuild: one hit, still one save
        self::assertSame(1, $pool->hits);
        self::assertSame(1, $pool->saves);
        self::assertEquals($original, $metadata);
        self::assertSame(Address::class, $metadata->class);
    }

    public function testInMemoryCacheShieldsThePoolWithinOneInstance(): void
    {
        // GIVEN
        $pool = new ArrayCachePool();
        $factory = new MetadataFactory(new TypeParser(), $pool);

        // WHEN the same class is requested twice from one factory
        $factory->for(Address::class);
        $factory->for(Address::class);

        // THEN the pool was consulted only once
        self::assertSame(1, $pool->misses + $pool->hits);
    }

    public function testDistinctClassesGetDistinctPoolEntries(): void
    {
        // GIVEN
        $pool = new ArrayCachePool();
        $factory = new MetadataFactory(new TypeParser(), $pool);

        // WHEN
        $factory->for(Address::class);
        $factory->for(Person::class);

        // THEN no key collision — and a fresh factory resolves each correctly
        self::assertSame(2, $pool->saves);
        $fresh = new MetadataFactory(new TypeParser(), $pool);
        self::assertSame(Person::class, $fresh->for(Person::class)->class);
        self::assertSame(Address::class, $fresh->for(Address::class)->class);
    }

    public function testForeignValueInThePoolIsIgnoredAndRebuilt(): void
    {
        // GIVEN a pool entry corrupted by another application
        $pool = new ArrayCachePool();
        $warm = new MetadataFactory(new TypeParser(), $pool);
        $warm->for(Address::class);
        self::assertNotNull($pool->lastSavedKey);
        $pool->storage[$pool->lastSavedKey] = 'garbage';

        // WHEN
        $factory = new MetadataFactory(new TypeParser(), $pool);
        $metadata = $factory->for(Address::class);

        // THEN the corrupt entry was ignored, metadata rebuilt and re-saved
        self::assertSame(Address::class, $metadata->class);
        self::assertSame(2, $pool->saves);
    }

    public function testRejectsVariadicConstructorParameters(): void
    {
        // GIVEN
        $factory = new MetadataFactory();

        // THEN
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('variadic');

        // WHEN
        $factory->for(Variadic::class);
    }

    public function testRejectsExtrasOnANonArrayParameter(): void
    {
        // GIVEN
        $factory = new MetadataFactory();

        // THEN
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('#[Extras]');

        // WHEN
        $factory->for(BadExtras::class);
    }

    public function testRejectsNativeUnionTypes(): void
    {
        // GIVEN
        $factory = new MetadataFactory();

        // THEN
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('union/intersection');

        // WHEN
        $factory->for(UnionNative::class);
    }
}
