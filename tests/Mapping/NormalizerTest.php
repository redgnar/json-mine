<?php

declare(strict_types=1);

namespace JsonMine\Tests\Mapping;

use JsonMine\MapperBuilder;
use JsonMine\Source;
use JsonMine\Tests\Fixture\Address;
use JsonMine\Tests\Fixture\Color;
use JsonMine\Tests\Fixture\CustomField;
use JsonMine\Tests\Fixture\Event;
use JsonMine\Tests\Fixture\Field;
use JsonMine\Tests\Fixture\FormDefinition;
use JsonMine\Tests\Fixture\GenericField;
use JsonMine\Tests\Fixture\Person;
use JsonMine\Tests\Fixture\PropsWithExtras;
use PHPUnit\Framework\TestCase;

/**
 * normalize(): typed values → json_encode-ready data, through the public API.
 */
final class NormalizerTest extends TestCase
{
    public function testScalarsArraysAndNullPassThrough(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN / THEN
        self::assertSame(42, $mapper->normalize(42));
        self::assertSame('x', $mapper->normalize('x'));
        self::assertTrue($mapper->normalize(true));
        self::assertNull($mapper->normalize(null));
        self::assertSame([1, 'a', ['k' => 2]], $mapper->normalize([1, 'a', ['k' => 2]]));
    }

    public function testEnumsAndDatesEmitJsonRepresentations(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN / THEN
        self::assertSame('red', $mapper->normalize(Color::Red));
        self::assertSame(
            '2026-08-08T12:00:00+00:00',
            $mapper->normalize(new \DateTimeImmutable('2026-08-08T12:00:00+00:00')),
        );
    }

    public function testNormalizesAnObjectTreeUnderJsonKeys(): void
    {
        // GIVEN #[Name('created_at')] on Event::$createdAt
        $mapper = MapperBuilder::create()->build();
        $event = new Event('Release', new \DateTimeImmutable('2026-08-08T12:00:00+00:00'), Color::Blue);

        // WHEN
        $normalized = $mapper->normalize($event);

        // THEN
        self::assertSame(
            ['title' => 'Release', 'created_at' => '2026-08-08T12:00:00+00:00', 'color' => 'blue'],
            $normalized,
        );
    }

    public function testOmitsDefaultsAndMissingNullables(): void
    {
        // GIVEN color equals its default, tags equal their default, nickname is a missing nullable
        $mapper = MapperBuilder::create()->build();
        $person = new Person('Ada', 36, new Address('Main 1', 'Lovelace'));

        // WHEN
        $normalized = $mapper->normalize($person);

        // THEN normalize() never invents keys hydration would restore anyway
        self::assertSame(
            ['name' => 'Ada', 'age' => 36, 'address' => ['street' => 'Main 1', 'city' => 'Lovelace']],
            $normalized,
        );
    }

    public function testVariantReEmitsTheDiscriminatorConsumedByHydration(): void
    {
        // GIVEN a closed-union variant (map on the Field root)
        $mapper = MapperBuilder::create()->build();
        $field = new \JsonMine\Tests\Fixture\TextField('email', 120);

        // WHEN
        $normalized = $mapper->normalize($field);

        // THEN
        self::assertSame(['type' => 'text', 'name' => 'email', 'maxLength' => 120], $normalized);
    }

    public function testRegistryVariantReEmitsItsDiscriminatorToo(): void
    {
        // GIVEN an open-union variant registered on the builder
        $mapper = MapperBuilder::create()
            ->withVariant(Field::class, 'custom', CustomField::class)
            ->build();

        // WHEN
        $normalized = $mapper->normalize(new CustomField('x', 'extra'));

        // THEN
        self::assertSame(['type' => 'custom', 'name' => 'x', 'custom' => 'extra'], $normalized);
    }

    public function testExtrasMergeBackFlat(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();
        $value = $mapper->map(PropsWithExtras::class, Source::json('{"id": "e1", "created_at": "now", "vendor_x": {"deep": 1}}'));

        // WHEN
        $normalized = $mapper->normalize($value);

        // THEN the unknown key survives at the top level, raw fragment intact
        self::assertIsArray($normalized);
        self::assertSame('e1', $normalized['id']);
        self::assertSame('now', $normalized['created_at']);
        self::assertInstanceOf(\stdClass::class, $normalized['vendor_x']);
        self::assertSame(1, $normalized['vendor_x']->deep);
    }

    public function testEveryExtrasEntrySurvives(): void
    {
        // GIVEN a bag with more than one unknown key
        $mapper = MapperBuilder::create()->build();
        $value = $mapper->map(PropsWithExtras::class, Source::json('{"id": "e1", "created_at": "now", "vendor_x": 1, "vendor_y": 2}'));

        // WHEN
        $normalized = $mapper->normalize($value);

        // THEN
        self::assertIsArray($normalized);
        self::assertSame(1, $normalized['vendor_x']);
        self::assertSame(2, $normalized['vendor_y']);
    }

    public function testFullRoundTripOfADiscriminatedUnionDocument(): void
    {
        // GIVEN a form definition with unions, lists, and an unknown-variant fallback
        $mapper = MapperBuilder::create()
            ->withVariantFallback(Field::class, GenericField::class)
            ->build();
        $json = <<<'JSON'
            {
                "id": "form-1",
                "fields": [
                    {"type": "text", "name": "email", "maxLength": 120},
                    {"type": "select", "name": "country", "options": ["pl", "de"]},
                    {"type": "webhook", "name": "hook", "url": "https://x"}
                ]
            }
            JSON;

        // WHEN load → normalize
        $normalized = $mapper->normalize($mapper->map(FormDefinition::class, Source::json($json)));

        // THEN the document survives losslessly (including the unknown variant)
        self::assertEquals(json_decode($json, true), json_decode(json_encode($normalized, \JSON_THROW_ON_ERROR), true));
    }

    public function testPropertyPhaseMembersFollowTheSameOmissionRules(): void
    {
        // GIVEN a property-hydrated object: status equals its default,
        // note is a missing nullable, nums differs from its default
        $mapper = MapperBuilder::create()->build();
        $value = $mapper->map(
            \JsonMine\Tests\Fixture\PropsOnly::class,
            Source::json('{"id": "p1", "name": "Ada", "count": 3, "nums": [1]}'),
        );

        // WHEN
        $normalized = $mapper->normalize($value);

        // THEN status/note are omitted, everything else (including members
        // after the omitted ones) is present
        self::assertSame(
            ['id' => 'p1', 'name' => 'Ada', 'count' => 3, 'nums' => [1]],
            $normalized,
        );
    }

    public function testChangedDefaultIsEmitted(): void
    {
        // GIVEN status no longer equals its declared default
        $mapper = MapperBuilder::create()->build();
        $value = $mapper->map(
            \JsonMine\Tests\Fixture\PropsOnly::class,
            Source::json('{"id": "p1", "name": "Ada", "count": 0, "status": "open"}'),
        );

        // WHEN
        $normalized = $mapper->normalize($value);

        // THEN
        self::assertIsArray($normalized);
        self::assertSame('open', $normalized['status']);
    }

    public function testNullableMemberWithoutDefaultIsEmittedWhenNotNull(): void
    {
        // GIVEN ?array $tags carries a value (no declared default)
        $mapper = MapperBuilder::create()->build();
        $value = new \JsonMine\Tests\Fixture\NullableTags(['a']);

        // WHEN
        $normalized = $mapper->normalize($value);

        // THEN
        self::assertSame(['tags' => ['a']], $normalized);
    }

    public function testMembersAfterAnOmittedParameterAreStillEmitted(): void
    {
        // GIVEN tags equals its default while the later nickname parameter carries a value
        $mapper = MapperBuilder::create()->build();
        $person = new Person('Ada', 36, new Address('s', 'c'), [], 'countess');

        // WHEN
        $normalized = $mapper->normalize($person);

        // THEN
        self::assertIsArray($normalized);
        self::assertArrayNotHasKey('tags', $normalized);
        self::assertSame('countess', $normalized['nickname']);
    }

    public function testParametersAfterTheExtrasBagAreStillEmitted(): void
    {
        // GIVEN #[Extras] is the first constructor parameter
        $mapper = MapperBuilder::create()->build();
        $value = new \JsonMine\Tests\Fixture\WithExtrasFirst(['vendor_x' => 1], 'w1');

        // WHEN
        $normalized = $mapper->normalize($value);

        // THEN
        self::assertSame(['id' => 'w1', 'vendor_x' => 1], $normalized);
    }

    public function testEmptyObjectEncodesAsJsonObject(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $normalized = $mapper->normalize(new \JsonMine\Tests\Fixture\NoConstructor());

        // THEN {} rather than []
        self::assertSame('{}', json_encode($normalized, \JSON_THROW_ON_ERROR));
    }

    public function testSharingAnObjectBetweenBranchesIsAllowed(): void
    {
        // GIVEN the same Address instance used twice
        $mapper = MapperBuilder::create()->build();
        $address = new Address('Main 1', 'X');

        // WHEN
        $normalized = $mapper->normalize([$address, $address]);

        // THEN
        self::assertIsArray($normalized);
        self::assertCount(2, $normalized);
        self::assertSame($normalized[0], $normalized[1]);
    }

    public function testCyclesAreRejectedWithAClearError(): void
    {
        // GIVEN an object graph with a cycle
        $mapper = MapperBuilder::create()->build();
        $holder = new \JsonMine\Tests\Fixture\UntypedExtras(['self' => null]);
        $holder->bag = ['self' => $holder];

        // THEN
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cycle');

        // WHEN
        $mapper->normalize($holder);
    }

    public function testNormalizeIsTheInverseOfMapForPlainClasses(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();
        $json = '{"name": "Ada", "age": 36, "address": {"street": "s", "city": "c"}, "tags": ["math"], "nickname": "countess"}';

        // WHEN
        $roundTripped = $mapper->normalize($mapper->map(Person::class, Source::json($json)));

        // THEN
        self::assertEquals(json_decode($json, true), $roundTripped);
    }
}
