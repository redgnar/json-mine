<?php

declare(strict_types=1);

namespace Ingot\Tests\Mapping\Type;

use Ingot\Mapping\Type\ClassType;
use Ingot\Mapping\Type\DateTimeType;
use Ingot\Mapping\Type\EnumType;
use Ingot\Mapping\Type\ListType;
use Ingot\Mapping\Type\MapType;
use Ingot\Mapping\Type\MixedType;
use Ingot\Mapping\Type\NullableType;
use Ingot\Mapping\Type\ScalarKind;
use Ingot\Mapping\Type\ScalarType;
use Ingot\Mapping\Type\TypeParser;
use Ingot\Tests\Fixture\Address;
use Ingot\Tests\Fixture\Color;
use Ingot\Tests\Fixture\Weekday;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TypeParser::class)]
final class TypeParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ScalarKind}>
     */
    public static function scalarCases(): iterable
    {
        yield 'int' => ['int', ScalarKind::Integer];
        yield 'integer alias' => ['integer', ScalarKind::Integer];
        yield 'float' => ['float', ScalarKind::Float];
        yield 'double alias' => ['double', ScalarKind::Float];
        yield 'string' => ['string', ScalarKind::String];
        yield 'bool' => ['bool', ScalarKind::Boolean];
        yield 'boolean alias' => ['boolean', ScalarKind::Boolean];
        yield 'case-insensitive' => ['INT', ScalarKind::Integer];
    }

    #[DataProvider('scalarCases')]
    public function testParsesScalars(string $input, ScalarKind $expected): void
    {
        // GIVEN a scalar type string ($input)

        // WHEN
        $node = new TypeParser()->parse($input);

        // THEN
        self::assertInstanceOf(ScalarType::class, $node);
        self::assertSame($expected, $node->kind);
    }

    public function testParsesQuestionMarkNullable(): void
    {
        // GIVEN / WHEN
        $node = new TypeParser()->parse('?int');

        // THEN
        self::assertInstanceOf(NullableType::class, $node);
        self::assertInstanceOf(ScalarType::class, $node->inner);
        self::assertSame(ScalarKind::Integer, $node->inner->kind);
    }

    public function testParsesUnionWithNullAsNullable(): void
    {
        // GIVEN / WHEN
        $node = new TypeParser()->parse('string|null');

        // THEN
        self::assertInstanceOf(NullableType::class, $node);
        self::assertInstanceOf(ScalarType::class, $node->inner);
        self::assertSame(ScalarKind::String, $node->inner->kind);
    }

    public function testRejectsWiderUnions(): void
    {
        // GIVEN
        $parser = new TypeParser();

        // THEN
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only "T|null" unions are supported');

        // WHEN
        $parser->parse('int|string');
    }

    public function testParsesListOfClass(): void
    {
        // GIVEN / WHEN
        $node = new TypeParser()->parse('list<' . Address::class . '>');

        // THEN
        self::assertInstanceOf(ListType::class, $node);
        self::assertInstanceOf(ClassType::class, $node->item);
        self::assertSame(Address::class, $node->item->class);
    }

    public function testParsesNestedGenerics(): void
    {
        // GIVEN / WHEN
        $node = new TypeParser()->parse('list<list<int>>');

        // THEN
        self::assertInstanceOf(ListType::class, $node);
        self::assertInstanceOf(ListType::class, $node->item);
        self::assertInstanceOf(ScalarType::class, $node->item->item);
    }

    public function testParsesMapWithKeyAndValue(): void
    {
        // GIVEN / WHEN
        $node = new TypeParser()->parse('array<string, int>');

        // THEN
        self::assertInstanceOf(MapType::class, $node);
        self::assertInstanceOf(ScalarType::class, $node->value);
        self::assertSame(ScalarKind::Integer, $node->value->kind);
    }

    public function testParsesMapWithValueOnly(): void
    {
        // GIVEN / WHEN
        $node = new TypeParser()->parse('array<bool>');

        // THEN
        self::assertInstanceOf(MapType::class, $node);
        self::assertInstanceOf(ScalarType::class, $node->value);
        self::assertSame(ScalarKind::Boolean, $node->value->kind);
    }

    public function testRejectsUnsupportedArrayKeyType(): void
    {
        // GIVEN
        $parser = new TypeParser();

        // THEN the message names the offending key type, not the value type
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported array key type "bool"');

        // WHEN
        $parser->parse('array<bool, int>');
    }

    public function testRejectsLeadingGarbageBeforeArrayGenerics(): void
    {
        // GIVEN
        $parser = new TypeParser();

        // THEN
        $this->expectException(\InvalidArgumentException::class);

        // WHEN
        $parser->parse('xarray<int>');
    }

    public function testRejectsTrailingGarbageAfterArrayGenerics(): void
    {
        // GIVEN
        $parser = new TypeParser();

        // THEN
        $this->expectException(\InvalidArgumentException::class);

        // WHEN
        $parser->parse('array<int>x');
    }

    public function testParsesMultilineArrayGenerics(): void
    {
        // GIVEN / WHEN
        $node = new TypeParser()->parse("array<\nint\n>");

        // THEN
        self::assertInstanceOf(MapType::class, $node);
    }

    public function testParsesUnionOfGenericAndNull(): void
    {
        // GIVEN the '|' split must respect bracket depth
        $node = new TypeParser()->parse('list<int>|null');

        // THEN
        self::assertInstanceOf(NullableType::class, $node);
        self::assertInstanceOf(ListType::class, $node->inner);
    }

    public function testResolvesBareInterfaceNameAgainstTheProvidedNamespace(): void
    {
        // GIVEN / WHEN
        $node = new TypeParser()->parse('SchemaVault', 'Ingot\Schema');

        // THEN
        self::assertInstanceOf(ClassType::class, $node);
        self::assertSame(\Ingot\Schema\SchemaVault::class, $node->class);
    }

    public function testRejectsUnknownTypeEvenWithANamespaceProvided(): void
    {
        // GIVEN
        $parser = new TypeParser();

        // THEN
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown type or class');

        // WHEN
        $parser->parse('NoSuchClazz', 'Ingot\Tests\Fixture');
    }

    public function testBareArrayIsAMapOfMixed(): void
    {
        // GIVEN / WHEN
        $node = new TypeParser()->parse('array');

        // THEN
        self::assertInstanceOf(MapType::class, $node);
        self::assertInstanceOf(MixedType::class, $node->value);
    }

    public function testParsesMixed(): void
    {
        // GIVEN / WHEN
        $node = new TypeParser()->parse('mixed');

        // THEN
        self::assertInstanceOf(MixedType::class, $node);
    }

    public function testParsesBackedEnum(): void
    {
        // GIVEN / WHEN
        $node = new TypeParser()->parse(Color::class);

        // THEN
        self::assertInstanceOf(EnumType::class, $node);
        self::assertSame(Color::class, $node->enum);
    }

    public function testRejectsPureEnum(): void
    {
        // GIVEN
        $parser = new TypeParser();

        // THEN
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not backed');

        // WHEN
        $parser->parse(Weekday::class);
    }

    public function testParsesDateTimeImmutable(): void
    {
        // GIVEN / WHEN
        $node = new TypeParser()->parse(\DateTimeImmutable::class);

        // THEN
        self::assertInstanceOf(DateTimeType::class, $node);
    }

    public function testParsesClassWithLeadingBackslash(): void
    {
        // GIVEN / WHEN
        $node = new TypeParser()->parse('\\' . Address::class);

        // THEN
        self::assertInstanceOf(ClassType::class, $node);
        self::assertSame(Address::class, $node->class);
    }

    public function testResolvesBareClassNameAgainstTheProvidedNamespace(): void
    {
        // GIVEN a docblock type relative to its declaring class namespace
        $parser = new TypeParser();

        // WHEN
        $node = $parser->parse('list<Address>', 'Ingot\Tests\Fixture');

        // THEN
        self::assertInstanceOf(ListType::class, $node);
        self::assertInstanceOf(ClassType::class, $node->item);
        self::assertSame(Address::class, $node->item->class);
    }

    public function testRejectsLeadingGarbageBeforeGenerics(): void
    {
        // GIVEN
        $parser = new TypeParser();

        // THEN 'xlist<int>' is not a list type
        $this->expectException(\InvalidArgumentException::class);

        // WHEN
        $parser->parse('xlist<int>');
    }

    public function testRejectsTrailingGarbageAfterGenerics(): void
    {
        // GIVEN
        $parser = new TypeParser();

        // THEN 'list<int>x' is not a list type
        $this->expectException(\InvalidArgumentException::class);

        // WHEN
        $parser->parse('list<int>x');
    }

    public function testParsesMultilineGenerics(): void
    {
        // GIVEN a docblock type spanning lines
        $node = new TypeParser()->parse("list<\nint\n>");

        // THEN
        self::assertInstanceOf(ListType::class, $node);
        self::assertInstanceOf(ScalarType::class, $node->item);
    }

    public function testParsesNonEmptyList(): void
    {
        // GIVEN / WHEN
        $node = new TypeParser()->parse('non-empty-list<string>');

        // THEN
        self::assertInstanceOf(ListType::class, $node);
    }

    public function testUnionNullDetectionIsCaseInsensitiveAndOrderIndependent(): void
    {
        // GIVEN / WHEN
        $first = new TypeParser()->parse('NULL|string');
        $second = new TypeParser()->parse(' string | null ');

        // THEN
        self::assertInstanceOf(NullableType::class, $first);
        self::assertInstanceOf(NullableType::class, $second);
    }

    public function testMapKeyTypeIsCaseInsensitiveAndTrimmed(): void
    {
        // GIVEN / WHEN
        $node = new TypeParser()->parse('array< STRING ,  int >');

        // THEN
        self::assertInstanceOf(MapType::class, $node);
        self::assertInstanceOf(ScalarType::class, $node->value);
        self::assertSame(ScalarKind::Integer, $node->value->kind);
    }

    public function testNestedMapWithCommasParsesByBracketDepth(): void
    {
        // GIVEN a comma nested inside the value type
        $node = new TypeParser()->parse('array<string, array<string, int>>');

        // THEN the top-level split ignored the nested comma
        self::assertInstanceOf(MapType::class, $node);
        self::assertInstanceOf(MapType::class, $node->value);
        self::assertInstanceOf(ScalarType::class, $node->value->value);
    }

    public function testParsesInterfaceAsClassType(): void
    {
        // GIVEN / WHEN
        $node = new TypeParser()->parse(\Stringable::class);

        // THEN
        self::assertInstanceOf(ClassType::class, $node);
        self::assertSame(\Stringable::class, $node->class);
    }

    public function testParsesDateTimeInterfaceAsDateTimeTarget(): void
    {
        // GIVEN / WHEN
        $node = new TypeParser()->parse(\DateTimeInterface::class);

        // THEN
        self::assertInstanceOf(DateTimeType::class, $node);
    }

    public function testResolvesBareEnumNameAgainstTheProvidedNamespace(): void
    {
        // GIVEN / WHEN
        $node = new TypeParser()->parse('Color', 'Ingot\Tests\Fixture');

        // THEN
        self::assertInstanceOf(EnumType::class, $node);
        self::assertSame(Color::class, $node->enum);
    }

    public function testRejectsUnknownType(): void
    {
        // GIVEN
        $parser = new TypeParser();

        // THEN
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown type or class');

        // WHEN
        $parser->parse('NoSuch\Clazz');
    }

    public function testRejectsEmptyType(): void
    {
        // GIVEN
        $parser = new TypeParser();

        // THEN
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty type');

        // WHEN
        $parser->parse('   ');
    }
}
