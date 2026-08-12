<?php

declare(strict_types=1);

namespace Ingot\Tests\Mapping;

use Ingot\Coercion;
use Ingot\MapperBuilder;
use Ingot\Source;
use Ingot\Tests\Fixture\DocblockOnly;
use Ingot\Tests\Fixture\Event;
use Ingot\Tests\Fixture\NoConstructor;
use Ingot\Tests\Fixture\NullableTags;
use Ingot\Tests\Fixture\TwoDocblockParams;
use Ingot\Tests\Fixture\UntypedExtras;
use Ingot\Tests\Fixture\Widget;
use Ingot\Tests\Fixture\WithExtrasFirst;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Scalar handling, coercion-table boundaries, and metadata edge cases,
 * exercised through the public API.
 */
final class ScalarAndEdgeCaseTest extends TestCase
{
    public function testStrictFloatAcceptsFloatsAndWholeNumbers(): void
    {
        // GIVEN JSON has no float/int distinction for whole numbers
        $mapper = MapperBuilder::create()->build();

        // WHEN
        /** @var list<float> $floats */
        $floats = $mapper->map('list<float>', Source::json('[1.5, 2]'));

        // THEN whole numbers arrive as true floats
        self::assertSame([1.5, 2.0], $floats);
    }

    public function testStrictFloatRejectsNumericStrings(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap('list<float>', Source::json('["1.5"]'));

        // THEN
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.type', $error->code);
        self::assertSame('Expected float, got string.', $error->message);
        self::assertSame('/0', $error->pointer->toString());
    }

    public function testStrictIntRejectsFloats(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap('list<int>', Source::json('[1.5]'));

        // THEN
        self::assertSame('Expected int, got float.', $result->errors()->errors[0]->message);
    }

    public function testMapsBooleansAndReportsExactMessage(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        /** @var array<string, bool> $flags */
        $flags = $mapper->map('array<string, bool>', Source::json('{"on": true, "off": false}'));
        $failure = $mapper->tryMap('array<string, bool>', Source::json('{"on": "yes"}'));

        // THEN
        self::assertSame(['on' => true, 'off' => false], $flags);
        self::assertSame('Expected bool, got string.', $failure->errors()->errors[0]->message);
    }

    public function testStringTypeErrorMessage(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap('list<string>', Source::json('[7]'));

        // THEN
        self::assertSame('Expected string, got int.', $result->errors()->errors[0]->message);
    }

    /**
     * @return iterable<string, array{string, string, mixed}>
     */
    public static function laxCoercions(): iterable
    {
        yield 'numeric string to int' => ['list<int>', '["42"]', [42]];
        yield 'negative numeric string to int' => ['list<int>', '["-7"]', [-7]];
        yield 'numeric string to float' => ['list<float>', '["1.25"]', [1.25]];
        yield 'int to string' => ['list<string>', '[42]', ['42']];
        yield 'float to string' => ['list<string>', '[1.5]', ['1.5']];
        yield 'one to true' => ['list<bool>', '[1]', [true]];
        yield 'zero to false' => ['list<bool>', '[0]', [false]];
        yield '"true" to true' => ['list<bool>', '["true"]', [true]];
        yield '"false" to false' => ['list<bool>', '["false"]', [false]];
    }

    #[DataProvider('laxCoercions')]
    public function testLaxCoercionTable(string $target, string $json, mixed $expected): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->withCoercion(Coercion::Lax)->build();

        // WHEN
        $value = $mapper->map($target, Source::json($json));

        // THEN
        self::assertSame($expected, $value);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function laxRejections(): iterable
    {
        yield 'letters are not an int' => ['list<int>', '["abc"]'];
        yield 'trailing garbage is not an int' => ['list<int>', '["12a"]'];
        yield 'leading garbage is not an int' => ['list<int>', '["a12"]'];
        yield 'a float string is not an int' => ['list<int>', '["3.5"]'];
        yield 'letters are not a float' => ['list<float>', '["abc"]'];
        yield 'arbitrary number is not a bool' => ['list<bool>', '[2]'];
        yield 'arbitrary string is not a bool' => ['list<bool>', '["yes"]'];
        yield 'bool is not a string' => ['list<string>', '[true]'];
    }

    #[DataProvider('laxRejections')]
    public function testLaxCoercionRejectsWhatIsNotInTheTable(string $target, string $json): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->withCoercion(Coercion::Lax)->build();

        // WHEN
        $result = $mapper->tryMap($target, Source::json($json));

        // THEN
        self::assertFalse($result->isSuccess());
        self::assertSame('mapping.type', $result->errors()->errors[0]->code);
    }

    public function testMixedTargetReturnsTheDecodedValueAsIs(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $value = $mapper->map('mixed', Source::json('{"anything": [1, "x"]}'));

        // THEN
        self::assertInstanceOf(\stdClass::class, $value);
        self::assertSame([1, 'x'], $value->anything);
    }

    public function testStdClassTargetPassesObjectsThrough(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $value = $mapper->map(\stdClass::class, Source::json('{"a": 1}'));
        $failure = $mapper->tryMap(\stdClass::class, Source::json('[1]'));

        // THEN
        self::assertObjectHasProperty('a', $value);
        self::assertSame(1, $value->a);
        self::assertSame('mapping.type', $failure->errors()->errors[0]->code);
    }

    public function testMapsIntBackedEnums(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        /** @var list<\Ingot\Tests\Fixture\Priority> $priorities */
        $priorities = $mapper->map('list<' . \Ingot\Tests\Fixture\Priority::class . '>', Source::json('[1, 2]'));
        $failure = $mapper->tryMap('list<' . \Ingot\Tests\Fixture\Priority::class . '>', Source::json('[3]'));

        // THEN
        self::assertSame([\Ingot\Tests\Fixture\Priority::Low, \Ingot\Tests\Fixture\Priority::High], $priorities);
        self::assertSame('Not a valid value — allowed: 1, 2.', $failure->errors()->errors[0]->message);
    }

    public function testExplicitNullIsAcceptedByNativeNullableParameters(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN maxLength (?int) is explicitly null
        $field = $mapper->map(
            \Ingot\Tests\Fixture\TextField::class,
            Source::json('{"name": "email", "maxLength": null}'),
        );

        // THEN
        self::assertNull($field->maxLength);
    }

    public function testExtrasOnNativeMixedParameterIsAllowed(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $value = $mapper->map(\Ingot\Tests\Fixture\MixedExtras::class, Source::json('{"id": "m1", "y": 2}'));

        // THEN
        self::assertSame(['y' => 2], $value->bag);
    }

    public function testAllBadListItemsAreReported(): void
    {
        // GIVEN two bad items among good ones
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap('list<int>', Source::json('[1, "a", 2, "b"]'));

        // THEN both problems are reported with their indexes
        self::assertCount(2, $result->errors());
        self::assertSame('/1', $result->errors()->errors[0]->pointer->toString());
        self::assertSame('/3', $result->errors()->errors[1]->pointer->toString());
    }

    public function testAllBadMapValuesAreReported(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap('array<string, int>', Source::json('{"a": "x", "b": 1, "c": "y"}'));

        // THEN
        self::assertCount(2, $result->errors());
        self::assertSame('/a', $result->errors()->errors[0]->pointer->toString());
        self::assertSame('/c', $result->errors()->errors[1]->pointer->toString());
    }

    public function testExactEnumErrorMessage(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(Event::class, Source::json('{"title": "x", "created_at": "2026-01-01", "color": "green"}'));

        // THEN the whole message (including the allowed-values list) is stable
        self::assertSame("Not a valid value — allowed: 'red', 'blue'.", $result->errors()->errors[0]->message);
    }

    public function testNonStringDateIsATypeErrorMentioningTheActualType(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(Event::class, Source::json('{"title": "x", "created_at": 123}'));

        // THEN
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.type', $error->code);
        self::assertSame('Expected a date-time string, got int.', $error->message);
    }

    public function testUnionRootWithNoKnownVariantsReportsUnknownVariant(): void
    {
        // GIVEN a #[Discriminator] root whose variants come from plugins only
        $mapper = MapperBuilder::create()->build();

        // WHEN nothing was registered
        $result = $mapper->tryMap(Widget::class, Source::json('{"kind": "gauge"}'));

        // THEN it is a data error, not a crash
        self::assertSame('mapping.unknown_variant', $result->errors()->errors[0]->code);
    }

    public function testMapsClassWithoutConstructor(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(NoConstructor::class, Source::json('{}'));

        // THEN
        self::assertTrue($result->isSuccess());
        self::assertCount(0, $result->errors());
    }

    public function testExtrasBagMayPrecedeOtherParameters(): void
    {
        // GIVEN #[Extras] is the first constructor parameter
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $value = $mapper->map(WithExtrasFirst::class, Source::json('{"id": "w1", "custom": 7}'));

        // THEN
        self::assertSame('w1', $value->id);
        self::assertSame(['custom' => 7], $value->extras);
    }

    public function testExtrasOnUntypedParameterCollectsUnknownKeys(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $value = $mapper->map(UntypedExtras::class, Source::json('{"x": 1}'));

        // THEN
        self::assertSame(['x' => 1], $value->bag);
    }

    public function testDocblockTypeWorksWithoutANativeType(): void
    {
        // GIVEN a parameter typed only by its docblock
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $value = $mapper->map(DocblockOnly::class, Source::json('{"nums": [1, 2]}'));
        $failure = $mapper->tryMap(DocblockOnly::class, Source::json('{"nums": ["x"]}'));

        // THEN the docblock type is enforced
        self::assertSame([1, 2], $value->nums);
        self::assertSame('/nums/0', $failure->errors()->errors[0]->pointer->toString());
    }

    public function testNativeNullabilitySurvivesDocblockRefinement(): void
    {
        // GIVEN ?array refined by @param list<string>
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $withNull = $mapper->map(NullableTags::class, Source::json('{"tags": null}'));
        $withList = $mapper->map(NullableTags::class, Source::json('{"tags": ["a"]}'));

        // THEN both null and a typed list are accepted
        self::assertNull($withNull->tags);
        self::assertSame(['a'], $withList->tags);
    }

    public function testEveryDocblockParamIsApplied(): void
    {
        // GIVEN two docblock-typed parameters
        $mapper = MapperBuilder::create()->build();

        // WHEN the second parameter gets a wrongly-typed item
        $result = $mapper->tryMap(TwoDocblockParams::class, Source::json('{"labels": ["a"], "counts": ["1"]}'));

        // THEN the second @param declaration was honored too
        self::assertFalse($result->isSuccess());
        self::assertSame('/counts/0', $result->errors()->errors[0]->pointer->toString());
    }

    public function testUnexpectedKeyPreventsObjectConstruction(): void
    {
        // GIVEN a payload that would explode in the constructor,
        // plus an unexpected key detected earlier
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(
            \Ingot\Tests\Fixture\Amount::class,
            Source::json('{"cents": -5, "extra": true}'),
        );

        // THEN only the unexpected-key error is reported — the constructor never ran
        self::assertCount(1, $result->errors());
        self::assertSame('mapping.unexpected_key', $result->errors()->errors[0]->code);
    }

    public function testTwoValidatorsOnTheSameClassBothRun(): void
    {
        // GIVEN two validators bound to one class, after an unrelated binding
        $mapper = MapperBuilder::create()
            ->withValidator(NoConstructor::class, new \Ingot\Tests\Fixture\AlwaysErrorValidator())
            ->withValidator(\Ingot\Tests\Fixture\Address::class, new \Ingot\Tests\Fixture\AlwaysErrorValidator())
            ->withValidator(\Ingot\Tests\Fixture\Address::class, new \Ingot\Tests\Fixture\AddressCityValidator())
            ->build();

        // WHEN
        $result = $mapper->tryMap(\Ingot\Tests\Fixture\Address::class, Source::json('{"street": "s", "city": ""}'));

        // THEN the unrelated binding was skipped, both bound validators ran
        $codes = array_map(static fn($error): string => $error->code, $result->errors()->errors);
        sort($codes);
        self::assertSame(['address.city.empty', 'always.error'], $codes);
    }

    public function testMapperIsReusableAcrossCalls(): void
    {
        // GIVEN one built mapper
        $mapper = MapperBuilder::create()->build();

        // WHEN mapped twice, including one failure in between
        $first = $mapper->tryMap(NoConstructor::class, Source::json('{}'));
        $failure = $mapper->tryMap('list<int>', Source::json('["x"]'));
        $second = $mapper->tryMap(NoConstructor::class, Source::json('{}'));

        // THEN state (collected errors) does not leak between calls
        self::assertTrue($first->isSuccess());
        self::assertCount(1, $failure->errors());
        self::assertTrue($second->isSuccess());
        self::assertCount(0, $second->errors());
    }
}
