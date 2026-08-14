<?php

declare(strict_types=1);

namespace Ingot\Tests\Mapping;

use Ingot\Coercion;
use Ingot\Error\MappingError;
use Ingot\MapperBuilder;
use Ingot\Schema\OpisSchemaValidator;
use Ingot\SchemaGen\SchemaGenerator;
use Ingot\Source;
use Ingot\Tests\Fixture\BadConstraintBool;
use Ingot\Tests\Fixture\BadConstraintGroup;
use Ingot\Tests\Fixture\BadConstraintTarget;
use Ingot\Tests\Fixture\ConstrainedProp;
use Ingot\Tests\Fixture\EmptyConstraints;
use Ingot\Tests\Fixture\GuardedQuantity;
use Ingot\Tests\Fixture\InvalidConstraintPattern;
use Ingot\Tests\Fixture\InvertedBounds;
use Ingot\Tests\Fixture\InvertedExclusiveBounds;
use Ingot\Tests\Fixture\InvertedLengths;
use Ingot\Tests\Fixture\Money;
use Ingot\Tests\Fixture\NegativeMinLength;
use Ingot\Tests\Fixture\NonPositiveMultipleOf;
use Ingot\Tests\Fixture\Poll;
use Ingot\Tests\Fixture\StrayConstraintOnList;
use Ingot\Tests\Fixture\StrayConstraintOnMap;
use Ingot\Tests\Fixture\StrayConstraintOnString;
use Ingot\Tests\Fixture\TildePattern;
use Ingot\Tests\Fixture\UniqueBag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #[Constraints] behavior: members validated against the JSON Schema
 * validation keywords declared on them, all through the public API.
 */
final class ConstraintsTest extends TestCase
{
    public function testAcceptsValuesSatisfyingAllConstraints(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $money = $mapper->map(Money::class, Source::json('{"currency": "PLN", "amount": 12.5, "quantity": 10}'));

        // THEN
        self::assertSame('PLN', $money->currency);
        self::assertSame(12.5, $money->amount);
        self::assertSame(10, $money->quantity);
    }

    public function testAcceptsValuesExactlyOnInclusiveBounds(): void
    {
        // GIVEN minimum/maximum are inclusive, unlike their exclusive* siblings
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $bottom = $mapper->map(Money::class, Source::json('{"currency": "PLN", "amount": 0}'));
        $top = $mapper->map(Money::class, Source::json('{"currency": "PLN", "amount": 1000000}'));

        // THEN
        self::assertSame(0.0, $bottom->amount);
        self::assertSame(1000000.0, $top->amount);
    }

    public function testAcceptsLengthsAndCountsExactlyOnTheirBounds(): void
    {
        // GIVEN note is 1..2 characters, options 2..4 items, votes 1..3 properties
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $shortest = $mapper->map(Poll::class, Source::json('{"options": ["a", "b"], "votes": {"a": 1}, "note": "x"}'));
        $fullest = $mapper->map(Poll::class, Source::json('{"options": ["a", "b", "c", "d"], "votes": {"a": 1, "b": 2, "c": 3}, "note": "xy"}'));

        // THEN
        self::assertSame('x', $shortest->note);
        self::assertSame(['a' => 1], $shortest->votes);
        self::assertSame(['a', 'b', 'c', 'd'], $fullest->options);
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 3], $fullest->votes);
    }

    /**
     * @return iterable<string, array{class-string, string, string, string, string}>
     */
    public static function violations(): iterable
    {
        $money = static fn(string $overrides): string => \sprintf('{"currency": "PLN", "amount": 1.5%s}', $overrides === '' ? '' : ', ' . $overrides);
        $poll = static fn(string $overrides): string => \sprintf('{"options": ["a", "b"]%s}', $overrides === '' ? '' : ', ' . $overrides);

        yield 'min_length' => [Poll::class, $poll('"note": ""'), '/note', 'mapping.min_length', 'Must be at least 1 characters, got 0.'];
        yield 'max_length' => [Poll::class, $poll('"note": "abc"'), '/note', 'mapping.max_length', 'Must be at most 2 characters, got 3.'];
        yield 'pattern' => [Money::class, '{"currency": "pln", "amount": 1.5}', '/currency', 'mapping.pattern', '"pln" does not match pattern "^[A-Z]{3}$".'];
        yield 'minimum' => [Money::class, '{"currency": "PLN", "amount": -0.5}', '/amount', 'mapping.minimum', 'Must be >= 0.'];
        yield 'maximum' => [Money::class, '{"currency": "PLN", "amount": 1000001}', '/amount', 'mapping.maximum', 'Must be <= 1000000.'];
        yield 'exclusive_minimum' => [Money::class, $money('"quantity": 0'), '/quantity', 'mapping.exclusive_minimum', 'Must be > 0.'];
        yield 'exclusive_maximum' => [Money::class, $money('"quantity": 100'), '/quantity', 'mapping.exclusive_maximum', 'Must be < 100.'];
        yield 'multiple_of integer' => [Money::class, $money('"quantity": 7'), '/quantity', 'mapping.multiple_of', 'Must be a multiple of 5.'];
        yield 'multiple_of float' => [Money::class, '{"currency": "PLN", "amount": 9.999}', '/amount', 'mapping.multiple_of', 'Must be a multiple of 0.01.'];
        yield 'min_items' => [Poll::class, '{"options": ["a"]}', '/options', 'mapping.min_items', 'Must contain at least 2 items, got 1.'];
        yield 'max_items' => [Poll::class, '{"options": ["a", "b", "c", "d", "e"]}', '/options', 'mapping.max_items', 'Must contain at most 4 items, got 5.'];
        yield 'min_properties' => [Poll::class, $poll('"votes": {}'), '/votes', 'mapping.min_properties', 'Must contain at least 1 properties, got 0.'];
        yield 'max_properties' => [Poll::class, $poll('"votes": {"a": 1, "b": 2, "c": 3, "d": 4}'), '/votes', 'mapping.max_properties', 'Must contain at most 3 properties, got 4.'];
    }

    /**
     * @param class-string $target
     */
    #[DataProvider('violations')]
    public function testRejectsAValueViolatingItsConstraint(string $target, string $document, string $pointer, string $code, string $message): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap($target, Source::json($document));

        // THEN
        self::assertCount(1, $result->errors());
        $error = $result->errors()->errors[0];
        self::assertSame($code, $error->code);
        self::assertSame($pointer, $error->pointer->toString());
        self::assertSame($message, $error->message);
    }

    public function testFloatMultipleOfToleratesRepresentationNoise(): void
    {
        // GIVEN multipleOf 1.0 and values a hair's breadth (2^-34) off a
        // whole number — exactly the noise binary floats produce
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $above = $mapper->map(ConstrainedProp::class, Source::json('{"whole": 3.0000000000582077}'));
        $below = $mapper->map(ConstrainedProp::class, Source::json('{"whole": 2.9999999999417923}'));

        // THEN both count as multiples of 1.0
        self::assertSame(3.0000000000582077, $above->whole);
        self::assertSame(2.9999999999417923, $below->whole);
    }

    public function testFloatMultipleOfRejectsValuesPastTheTolerance(): void
    {
        // GIVEN deviations of 2^-33 and 2^-30 — at and beyond the tolerance
        $mapper = MapperBuilder::create()->build();

        foreach (['5.000000000116415', '2.9999999990686774'] as $value) {
            // WHEN
            $result = $mapper->tryMap(ConstrainedProp::class, Source::json(\sprintf('{"whole": %s}', $value)));

            // THEN
            $error = $result->errors()->errors[0];
            self::assertSame('mapping.multiple_of', $error->code);
            self::assertSame('/whole', $error->pointer->toString());
        }
    }

    public function testIntegerMultipleOfStaysExactBeyondFloatPrecision(): void
    {
        // GIVEN 2^62 + 1 — odd, but float arithmetic loses the trailing bit
        // and would call it even
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(ConstrainedProp::class, Source::json('{"even": 4611686018427387905}'));

        // THEN integer members are checked with integer arithmetic
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.multiple_of', $error->code);
        self::assertSame('/even', $error->pointer->toString());
    }

    public function testPatternContainingTheEngineDelimiterMatchesUnanchored(): void
    {
        // GIVEN the pattern 'a~b' — '~' is the engine's internal delimiter,
        // and JSON Schema patterns match anywhere unless anchored
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $matched = $mapper->map(TildePattern::class, Source::json('{"code": "xa~by"}'));
        $result = $mapper->tryMap(TildePattern::class, Source::json('{"code": "ab"}'));

        // THEN
        self::assertSame('xa~by', $matched->code);
        self::assertSame('mapping.pattern', $result->errors()->errors[0]->code);
    }

    public function testAConstraintViolationPreventsObjectConstruction(): void
    {
        // GIVEN a constructor that would itself reject the value
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(GuardedQuantity::class, Source::json('{"value": 0}'));

        // THEN only the constraint error reports — the constructor never ran
        self::assertCount(1, $result->errors());
        self::assertSame('mapping.minimum', $result->errors()->errors[0]->code);
    }

    public function testReportsEveryViolatedConstraintOnOneMember(): void
    {
        // GIVEN "x" is both too short for minLength 3 and off-pattern
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(Money::class, Source::json('{"currency": "x", "amount": 1.5}'));

        // THEN both violations land in the report, at the same pointer
        $errors = $result->errors()->errors;
        self::assertSame(['mapping.min_length', 'mapping.pattern'], array_map(static fn(MappingError $error): string => $error->code, $errors));
        self::assertSame(['/currency', '/currency'], array_map(static fn(MappingError $error): string => $error->pointer->toString(), $errors));
    }

    public function testStringLengthIsCountedInCodePointsNotBytes(): void
    {
        // GIVEN "żż" is 2 code points but 4 UTF-8 bytes, against maxLength 2
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $poll = $mapper->map(Poll::class, Source::json('{"options": ["a", "b"], "note": "żż"}'));

        // THEN
        self::assertSame('żż', $poll->note);
    }

    public function testANonUtf8StringFallsBackToByteLength(): void
    {
        // GIVEN a string only Source::array can carry — JSON input is UTF-8
        // by construction — measuring 2 bytes against maxLength 2
        $mapper = MapperBuilder::create()->build();
        $document = (object) ['options' => ['a', 'b'], 'note' => "\xC3("];

        // WHEN
        $poll = $mapper->map(Poll::class, Source::array($document));

        // THEN
        self::assertSame("\xC3(", $poll->note);
    }

    public function testNullPassesANullableConstrainedMember(): void
    {
        // GIVEN Poll::$note is ?string — constraints describe the non-null branch
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $poll = $mapper->map(Poll::class, Source::json('{"options": ["a", "b"], "note": null}'));

        // THEN
        self::assertNull($poll->note);
    }

    public function testRejectsDuplicateListItems(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(Poll::class, Source::json('{"options": ["a", "b", "a"]}'));

        // THEN the duplicate occurrence is pointed at, naming the original
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.unique_items', $error->code);
        self::assertSame('/options/2', $error->pointer->toString());
        self::assertSame('Items must be unique — duplicates the item at index 0.', $error->message);
        self::assertSame('a', $error->input);
    }

    public function testUniquenessComparesNumbersByValueLikeJsonSchema(): void
    {
        // GIVEN JSON Schema equality treats 1 and 1.0 as the same number
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(UniqueBag::class, Source::json('{"items": [1, 1.0]}'));

        // THEN
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.unique_items', $error->code);
        self::assertSame('/items/1', $error->pointer->toString());
    }

    public function testUniquenessIgnoresObjectKeyOrder(): void
    {
        // GIVEN two objects with the same entries in a different order
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(UniqueBag::class, Source::json('{"items": [{"a": 1, "b": 2}, {"b": 2, "a": 1}]}'));

        // THEN
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.unique_items', $error->code);
        self::assertSame('/items/1', $error->pointer->toString());
    }

    public function testEveryDuplicateOccurrenceIsReported(): void
    {
        // GIVEN a value repeated twice after its first occurrence
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(UniqueBag::class, Source::json('{"items": ["a", "b", "a", "a"]}'));

        // THEN each repeat points back at the original, not at earlier repeats
        $errors = $result->errors()->errors;
        self::assertCount(2, $errors);
        self::assertSame('/items/2', $errors[0]->pointer->toString());
        self::assertSame('/items/3', $errors[1]->pointer->toString());
        self::assertSame('Items must be unique — duplicates the item at index 0.', $errors[1]->message);
    }

    public function testUniquenessIgnoresAssociativeArrayKeyOrder(): void
    {
        // GIVEN objects arriving as associative arrays (Source::array input)
        $mapper = MapperBuilder::create()->build();
        $document = (object) ['items' => [['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]]];

        // WHEN
        $result = $mapper->tryMap(UniqueBag::class, Source::array($document));

        // THEN
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.unique_items', $error->code);
        self::assertSame('/items/1', $error->pointer->toString());
    }

    public function testUniquenessComparesNestedValuesCanonically(): void
    {
        // GIVEN equal lists whose objects differ only in key order
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(UniqueBag::class, Source::json('{"items": [[{"b": 1, "a": 2}], [{"a": 2, "b": 1}]]}'));

        // THEN canonicalization reaches inside nested arrays
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.unique_items', $error->code);
        self::assertSame('/items/1', $error->pointer->toString());
    }

    public function testUniquenessDistinguishesAListFromAStringKeyedObject(): void
    {
        // GIVEN [1] and {"0": 1} — different JSON values with equal entries
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $bag = $mapper->map(UniqueBag::class, Source::json('{"items": [[1], {"0": 1}]}'));

        // THEN
        self::assertCount(2, $bag->items);
    }

    public function testUniquenessDistinguishesObjectsByTheirEntries(): void
    {
        // GIVEN two objects sharing keys but not values
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $bag = $mapper->map(UniqueBag::class, Source::json('{"items": [{"a": 1}, {"a": 2}]}'));

        // THEN
        self::assertCount(2, $bag->items);
    }

    public function testUniquenessDistinguishesTypes(): void
    {
        // GIVEN 1 and "1" differ, as do an empty array and an empty object
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $bag = $mapper->map(UniqueBag::class, Source::json('{"items": [1, "1", [], {}]}'));

        // THEN
        self::assertCount(4, $bag->items);
        self::assertInstanceOf(\stdClass::class, $bag->items[3]);
    }

    public function testCountViolationsReportAlongsideItemErrors(): void
    {
        // GIVEN one item too few, and that item of the wrong type
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(Poll::class, Source::json('{"options": [5]}'));

        // THEN the count error does not swallow the per-item error
        $codes = array_map(static fn(MappingError $error): string => $error->code, $result->errors()->errors);
        self::assertContains('mapping.min_items', $codes);
        self::assertContains('mapping.type', $codes);
    }

    public function testLaxCoercionRunsBeforeConstraintChecks(): void
    {
        // GIVEN Lax mode turns "7" into 7, and only then the constraints apply
        $mapper = MapperBuilder::create()->withCoercion(Coercion::Lax)->build();

        // WHEN
        $result = $mapper->tryMap(Money::class, Source::json('{"currency": "PLN", "amount": 1.5, "quantity": "7"}'));

        // THEN the coerced 7 fails multipleOf 5 — not the type check
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.multiple_of', $error->code);
        self::assertSame('/quantity', $error->pointer->toString());
    }

    public function testConstraintsApplyToANonConstructorProperty(): void
    {
        // GIVEN #[Constraints(minimum: 1)] on a plain public property
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(ConstrainedProp::class, Source::json('{"rank": 0}'));

        // THEN
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.minimum', $error->code);
        self::assertSame('/rank', $error->pointer->toString());
    }

    public function testGeneratedSchemaAndEngineAgreeOnConstraints(): void
    {
        // GIVEN the schema generated from the constrained class
        $schema = new SchemaGenerator()->generate(Money::class);
        $validator = new OpisSchemaValidator();
        $mapper = MapperBuilder::create()->build();
        $invalid = '{"currency": "pln", "amount": -1, "quantity": 7}';
        $valid = '{"currency": "PLN", "amount": 9.99, "quantity": 10}';

        // WHEN / THEN both surfaces reject the same invalid document…
        self::assertFalse($validator->validate(json_decode($invalid, flags: \JSON_THROW_ON_ERROR), $schema)->isEmpty());
        self::assertFalse($mapper->tryMap(Money::class, Source::json($invalid))->isSuccess());

        // …and both accept the same valid one
        self::assertTrue($validator->validate(json_decode($valid, flags: \JSON_THROW_ON_ERROR), $schema)->isEmpty());
        self::assertTrue($mapper->tryMap(Money::class, Source::json($valid))->isSuccess());
    }

    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function configurationErrors(): iterable
    {
        yield 'keyword from another group' => [BadConstraintGroup::class, '/minLength do not apply to parameter "count".*minimum, maximum/'];
        yield 'list keyword on a string' => [StrayConstraintOnString::class, '/minItems do not apply to parameter "name".*minLength, maxLength, pattern/'];
        yield 'string keyword on a list' => [StrayConstraintOnList::class, '/minLength do not apply to parameter "tags".*minItems, maxItems, uniqueItems/'];
        yield 'list keyword on a map' => [StrayConstraintOnMap::class, '/minItems do not apply to parameter "scores".*minProperties, maxProperties/'];
        yield 'constraint on a bool member' => [BadConstraintBool::class, '/#\[Constraints\] does not apply to parameter "active"/'];
        yield 'minLength above maxLength' => [InvertedLengths::class, '/minLength \(5\) exceeds maxLength \(2\)/'];
        yield 'unconstrainable member kind' => [BadConstraintTarget::class, '/#\[Constraints\] does not apply to parameter "at"/'];
        yield 'empty attribute' => [EmptyConstraints::class, '/#\[Constraints\] on parameter "name" .*declares no keyword/'];
        yield 'negative length' => [NegativeMinLength::class, '/minLength on parameter "name" .*must be >= 0, got -1/'];
        yield 'minimum above maximum' => [InvertedBounds::class, '/minimum \(10\) exceeds maximum \(5\)/'];
        yield 'exclusive bounds leave no values' => [InvertedExclusiveBounds::class, '/exclusiveMinimum \(5\) must be below exclusiveMaximum \(5\)/'];
        yield 'non-positive multipleOf' => [NonPositiveMultipleOf::class, '/multipleOf on parameter "step" .*must be > 0, got 0/'];
        yield 'pattern that does not compile' => [InvalidConstraintPattern::class, '/The pattern "\[" on parameter "code" .*does not compile/'];
    }

    /**
     * @param class-string $target
     */
    #[DataProvider('configurationErrors')]
    public function testRejectsAMisconfiguredConstraintsAttribute(string $target, string $messagePattern): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // THEN a programmer error, not a data error
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches($messagePattern);

        // WHEN
        $mapper->map($target, Source::json('{}'));
    }
}
