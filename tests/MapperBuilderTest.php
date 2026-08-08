<?php

declare(strict_types=1);

namespace JsonMine\Tests;

use JsonMine\Coercion;
use JsonMine\MapperBuilder;
use JsonMine\Schema\InMemorySchemaVault;
use JsonMine\Schema\Schema;
use JsonMine\Source;
use JsonMine\Tests\Fixture\Address;
use JsonMine\Tests\Fixture\AlwaysErrorValidator;
use JsonMine\Tests\Fixture\CustomField;
use JsonMine\Tests\Fixture\Field;
use JsonMine\Tests\Fixture\GenericField;
use JsonMine\Tests\Fixture\RejectingSchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MapperBuilder::class)]
final class MapperBuilderTest extends TestCase
{
    public function testEveryWitherReturnsANewBuilder(): void
    {
        // GIVEN
        $base = MapperBuilder::create();

        // WHEN / THEN — the builder is immutable
        self::assertNotSame($base, $base->withSchemaValidator(new RejectingSchemaValidator()));
        self::assertNotSame($base, $base->withSchema(Address::class, Schema::fromDocument(true)));
        self::assertNotSame($base, $base->withSchemaResolver(static fn(string $class, mixed $document): ?Schema => null));
        self::assertNotSame($base, $base->withSchemaVault(new InMemorySchemaVault()));
        self::assertNotSame($base, $base->withCoercion(Coercion::Lax));
        self::assertNotSame($base, $base->withValidator(Address::class, new AlwaysErrorValidator()));
        self::assertNotSame($base, $base->withValidatorFactory(Address::class, static fn(): AlwaysErrorValidator => new AlwaysErrorValidator()));
        self::assertNotSame($base, $base->withVariant(Field::class, 'custom', CustomField::class));
        self::assertNotSame($base, $base->withVariantFallback(Field::class, GenericField::class));
    }

    public function testWitherDoesNotMutateTheOriginalBuilder(): void
    {
        // GIVEN a base builder from which a Lax variant was derived
        $base = MapperBuilder::create();
        $base->withCoercion(Coercion::Lax);

        // WHEN the base builder is used afterwards
        $result = $base->build()->tryMap(Address::class, Source::json('{"street": "s", "city": "c", "zip": "x"}'));

        // THEN it is still strict (the unexpected key is reported)
        self::assertFalse($result->isSuccess());
        self::assertSame('mapping.unexpected_key', $result->errors()->errors[0]->code);
    }

    public function testCustomSchemaValidatorReplacesTheDefaultBackend(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()
            ->withSchemaValidator(new RejectingSchemaValidator())
            ->withSchema(Address::class, Schema::fromDocument(true))
            ->build();

        // WHEN
        $result = $mapper->tryMap(Address::class, Source::json('{"street": "s", "city": "c"}'));

        // THEN the stub backend produced the schema report
        self::assertFalse($result->isSuccess());
        self::assertSame('stub.rejected', $result->errors()->errors[0]->code);
    }

    public function testSchemaResolversApplyToClassTargetsOnly(): void
    {
        // GIVEN a resolver claiming every class fails its schema
        $mapper = MapperBuilder::create()
            ->withSchemaResolver(static fn(string $class, mixed $document): Schema => Schema::fromDocument(false))
            ->build();

        // WHEN / THEN a class target is gated by the resolved schema…
        self::assertFalse($mapper->tryMap(Address::class, Source::json('{"street": "s", "city": "c"}'))->isSuccess());

        // …while a type-string target skips schema resolution entirely
        self::assertTrue($mapper->tryMap('list<int>', Source::json('[1, 2]'))->isSuccess());
    }

    public function testExternalVaultIsConsultedAfterExplicitBindings(): void
    {
        // GIVEN an external vault rejecting Address documents
        $vault = new InMemorySchemaVault()->bind(Address::class, Schema::fromDocument(false));
        $mapper = MapperBuilder::create()->withSchemaVault($vault)->build();

        // WHEN
        $result = $mapper->tryMap(Address::class, Source::json('{"street": "s", "city": "c"}'));

        // THEN
        self::assertFalse($result->isSuccess());
    }
}
