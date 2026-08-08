<?php

declare(strict_types=1);

namespace JsonMine\Tests\Mapping\Metadata;

use JsonMine\Mapping\Metadata\MetadataFactory;
use JsonMine\Tests\Fixture\Address;
use JsonMine\Tests\Fixture\BadExtras;
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
