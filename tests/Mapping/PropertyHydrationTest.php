<?php

declare(strict_types=1);

namespace JsonMine\Tests\Mapping;

use JsonMine\MapperBuilder;
use JsonMine\Source;
use JsonMine\Tests\Fixture\BadExtrasProp;
use JsonMine\Tests\Fixture\CtorInitialized;
use JsonMine\Tests\Fixture\GuardedProps;
use JsonMine\Tests\Fixture\HybridSlug;
use JsonMine\Tests\Fixture\PropsOnly;
use JsonMine\Tests\Fixture\PropsWithExtras;
use JsonMine\Tests\Fixture\UntypedExtrasProp;
use PHPUnit\Framework\TestCase;

/**
 * Hybrid hydration: constructor parameters first, then members the
 * constructor does not cover are set through reflection — public, private,
 * and uninitialized readonly properties alike.
 */
final class PropertyHydrationTest extends TestCase
{
    public function testHydratesClassWithoutConstructorThroughProperties(): void
    {
        // GIVEN a class with public, private, readonly, and docblock-typed properties
        $mapper = MapperBuilder::create()->build();
        $source = Source::json('{"id": "p1", "name": "Ada", "count": 3, "note": "hi", "status": "open", "nums": [1, 2]}');

        // WHEN
        $value = $mapper->map(PropsOnly::class, $source);

        // THEN every property kind was populated
        self::assertSame('p1', $value->id);
        self::assertSame('Ada', $value->name);
        self::assertSame(3, $value->count());
        self::assertSame('hi', $value->note);
        self::assertSame('open', $value->status);
        self::assertSame([1, 2], $value->nums);
    }

    public function testPropertyDefaultsAndNullablesApplyWhenKeysAreMissing(): void
    {
        // GIVEN status has a default, note is nullable
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $value = $mapper->map(PropsOnly::class, Source::json('{"id": "p1", "name": "Ada", "count": 0}'));

        // THEN
        self::assertSame('new', $value->status);
        self::assertNull($value->note);
        self::assertSame([], $value->nums);
    }

    public function testMissingRequiredPropertyIsReported(): void
    {
        // GIVEN "name" (typed, no default, not nullable) is absent
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(PropsOnly::class, Source::json('{"id": "p1", "count": 0}'));

        // THEN
        self::assertFalse($result->isSuccess());
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.missing_key', $error->code);
        self::assertStringContainsString('"name"', $error->message);
    }

    public function testPropertyTypesAreEnforced(): void
    {
        // GIVEN the docblock type list<int> on $nums
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(PropsOnly::class, Source::json('{"id": "p1", "name": "A", "count": 0, "nums": ["x"]}'));

        // THEN
        self::assertFalse($result->isSuccess());
        self::assertSame('/nums/0', $result->errors()->errors[0]->pointer->toString());
        self::assertSame('mapping.type', $result->errors()->errors[0]->code);
    }

    public function testConstructorRunsFirstThenUncoveredMembersAreSet(): void
    {
        // GIVEN a constructor covering $id, and a readonly $slug outside it
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $value = $mapper->map(HybridSlug::class, Source::json('{"id": "a", "slug": "b"}'));

        // THEN both paths were used on one object
        self::assertSame('a', $value->id);
        self::assertSame('b', $value->slug);
    }

    public function testSettingAConstructorInitializedReadonlyPropertyFails(): void
    {
        // GIVEN the constructor body already initialized readonly $v
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(CtorInitialized::class, Source::json('{"v": "x"}'));

        // THEN a data-level error, not a crash
        self::assertFalse($result->isSuccess());
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.property', $error->code);
        self::assertStringContainsString('"v"', $error->message);
        self::assertSame('x', $error->input);
    }

    public function testPropertyLevelNameAndExtrasWork(): void
    {
        // GIVEN #[Name('created_at')] and an #[Extras] property
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $value = $mapper->map(PropsWithExtras::class, Source::json('{"id": "e1", "created_at": "now", "vendor_x": 1}'));

        // THEN
        self::assertSame('now', $value->createdAt);
        self::assertSame(['vendor_x' => 1], $value->extras);
    }

    public function testFailedMemberPreventsThePropertyPhaseEntirely(): void
    {
        // GIVEN $req is missing; $v is present but would conflict if ever set
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(GuardedProps::class, Source::json('{"v": "x"}'));

        // THEN only the real problem is reported — no follow-up property conflict
        self::assertCount(1, $result->errors());
        self::assertSame('mapping.missing_key', $result->errors()->errors[0]->code);
    }

    public function testTypeFailureAlsoPreventsThePropertyPhase(): void
    {
        // GIVEN $req has a wrong type; $v is present but would conflict if ever set
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(GuardedProps::class, Source::json('{"req": 5, "v": "x"}'));

        // THEN exactly the type error, nothing more
        self::assertCount(1, $result->errors());
        self::assertSame('mapping.type', $result->errors()->errors[0]->code);
        self::assertSame('/req', $result->errors()->errors[0]->pointer->toString());
    }

    public function testFailedPropertyDoesNotStopConsumingLaterKeys(): void
    {
        // GIVEN the first property ($id) fails, later keys are fine
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(PropsOnly::class, Source::json('{"id": 1, "name": "A", "count": 0}'));

        // THEN only the type error is reported — later keys were consumed,
        // not misreported as unexpected
        self::assertCount(1, $result->errors());
        self::assertSame('/id', $result->errors()->errors[0]->pointer->toString());
    }

    public function testExtrasOnDocblockMixedPropertyIsAllowed(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $value = $mapper->map(UntypedExtrasProp::class, Source::json('{"id": "u1", "x": 1}'));

        // THEN
        self::assertSame(['x' => 1], $value->bag);
    }

    public function testRejectsExtrasOnANonArrayProperty(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // THEN
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('#[Extras]');

        // WHEN
        $mapper->map(BadExtrasProp::class, Source::json('{}'));
    }

    public function testUnexpectedKeysAreStillDetectedInStrictMode(): void
    {
        // GIVEN a class whose members are all properties
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(PropsOnly::class, Source::json('{"id": "p1", "name": "A", "count": 0, "bogus": 1}'));

        // THEN
        self::assertFalse($result->isSuccess());
        self::assertSame('mapping.unexpected_key', $result->errors()->errors[0]->code);
        self::assertSame('/bogus', $result->errors()->errors[0]->pointer->toString());
    }
}
