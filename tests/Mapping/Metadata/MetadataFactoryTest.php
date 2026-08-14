<?php

declare(strict_types=1);

namespace Ingot\Tests\Mapping\Metadata;

use Ingot\Mapping\Metadata\MetadataFactory;
use Ingot\Mapping\Type\TypeParser;
use Ingot\Tests\Fixture\Address;
use Ingot\Tests\Fixture\ArrayCachePool;
use Ingot\Tests\Fixture\BadExtras;
use Ingot\Tests\Fixture\Money;
use Ingot\Tests\Fixture\Person;
use Ingot\Tests\Fixture\UnionNative;
use Ingot\Tests\Fixture\Variadic;
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

    public function testConstraintMetadataSurvivesSerialization(): void
    {
        // GIVEN a class with #[Constraints] on scalar members
        $factory = new MetadataFactory();
        $metadata = $factory->for(Money::class);

        // WHEN the metadata takes a PSR-6-style serialization round-trip
        $revived = unserialize(serialize($metadata));

        // THEN nothing is lost — the constraints included
        self::assertEquals($metadata, $revived);
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
