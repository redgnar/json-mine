<?php

declare(strict_types=1);

namespace JsonMine\Tests\Mapping;

use JsonMine\Coercion;
use JsonMine\Error\MappingFailed;
use JsonMine\MapperBuilder;
use JsonMine\Schema\Schema;
use JsonMine\Source;
use JsonMine\Tests\Fixture\Address;
use JsonMine\Tests\Fixture\AddressCityValidator;
use JsonMine\Tests\Fixture\Amount;
use JsonMine\Tests\Fixture\Color;
use JsonMine\Tests\Fixture\CustomField;
use JsonMine\Tests\Fixture\Event;
use JsonMine\Tests\Fixture\Field;
use JsonMine\Tests\Fixture\FormDefinition;
use JsonMine\Tests\Fixture\GenericField;
use JsonMine\Tests\Fixture\Person;
use JsonMine\Tests\Fixture\SelectField;
use JsonMine\Tests\Fixture\TextField;
use JsonMine\Tests\Fixture\UniqueFieldNamesValidator;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end mapping through the public API (MapperBuilder → TreeMapper).
 */
final class MapperTest extends TestCase
{
    public function testMapsNestedReadonlyClassesWithListsAndOptionals(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();
        $source = Source::json(<<<'JSON'
            {
                "name": "Ada",
                "age": 36,
                "address": {"street": "Main 1", "city": "Lovelace"},
                "tags": ["math", "engines"]
            }
            JSON);

        // WHEN
        $person = $mapper->map(Person::class, $source);

        // THEN
        self::assertSame('Ada', $person->name);
        self::assertSame(36, $person->age);
        self::assertSame('Lovelace', $person->address->city);
        self::assertSame(['math', 'engines'], $person->tags);
        self::assertNull($person->nickname);
    }

    public function testMapsEnumsDatesAndRenamedKeys(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();
        $source = Source::json('{"title": "Release", "created_at": "2026-08-08T12:00:00+00:00", "color": "blue"}');

        // WHEN
        $event = $mapper->map(Event::class, $source);

        // THEN
        self::assertSame('Release', $event->title);
        self::assertSame('2026-08-08 12:00', $event->createdAt->format('Y-m-d H:i'));
        self::assertSame(Color::Blue, $event->color);
    }

    public function testEnumDefaultAppliesWhenKeyIsMissing(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $event = $mapper->map(Event::class, Source::json('{"title": "Release", "created_at": "2026-08-08"}'));

        // THEN
        self::assertSame(Color::Red, $event->color);
    }

    public function testAggregatesAllTypeErrorsAcrossSiblings(): void
    {
        // GIVEN name and age are both wrong
        $mapper = MapperBuilder::create()->build();
        $source = Source::json('{"name": 5, "age": "old", "address": {"street": "Main 1", "city": "X"}}');

        // WHEN
        $result = $mapper->tryMap(Person::class, $source);

        // THEN one pass reports both problems with exact pointers
        self::assertFalse($result->isSuccess());
        $byPointer = [];

        foreach ($result->errors() as $error) {
            $byPointer[$error->pointer->toString()] = $error->code;
        }

        self::assertSame(['/name' => 'mapping.type', '/age' => 'mapping.type'], $byPointer);
    }

    public function testReportsMissingRequiredKey(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(Address::class, Source::json('{"street": "Main 1"}'));

        // THEN
        self::assertFalse($result->isSuccess());
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.missing_key', $error->code);
        self::assertSame('', $error->pointer->toString());
        self::assertStringContainsString('"city"', $error->message);
    }

    public function testStrictModeRejectsNumericStrings(): void
    {
        // GIVEN Strict is the default
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(Person::class, Source::json('{"name": "Ada", "age": "36", "address": {"street": "s", "city": "c"}}'));

        // THEN
        self::assertFalse($result->isSuccess());
        self::assertSame('mapping.type', $result->errors()->errors[0]->code);
        self::assertSame('/age', $result->errors()->errors[0]->pointer->toString());
    }

    public function testLaxModeCoercesScalars(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->withCoercion(Coercion::Lax)->build();

        // WHEN
        $person = $mapper->map(Person::class, Source::json('{"name": "Ada", "age": "36", "address": {"street": "s", "city": "c"}}'));

        // THEN
        self::assertSame(36, $person->age);
    }

    public function testStrictModeReportsUnexpectedKeys(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(Address::class, Source::json('{"street": "Main 1", "city": "X", "zip": "00-001"}'));

        // THEN
        self::assertFalse($result->isSuccess());
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.unexpected_key', $error->code);
        self::assertSame('/zip', $error->pointer->toString());
        self::assertSame('00-001', $error->input);
    }

    public function testLaxModeIgnoresUnexpectedKeys(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->withCoercion(Coercion::Lax)->build();

        // WHEN
        $address = $mapper->map(Address::class, Source::json('{"street": "Main 1", "city": "X", "zip": "00-001"}'));

        // THEN
        self::assertSame('Main 1', $address->street);
    }

    public function testInvalidEnumValueListsAllowedValues(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(Event::class, Source::json('{"title": "x", "created_at": "2026-01-01", "color": "green"}'));

        // THEN
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.enum', $error->code);
        self::assertSame('/color', $error->pointer->toString());
        self::assertStringContainsString("'red'", $error->message);
        self::assertStringContainsString("'blue'", $error->message);
    }

    public function testInvalidDateIsAFormatError(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(Event::class, Source::json('{"title": "x", "created_at": "not-a-date"}'));

        // THEN
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.format', $error->code);
        self::assertSame('/created_at', $error->pointer->toString());
    }

    public function testMapsClosedDiscriminatedUnionFromTheAttributeMap(): void
    {
        // GIVEN #[Discriminator('type', map: [...])] on the Field root
        $mapper = MapperBuilder::create()->build();
        $source = Source::json(<<<'JSON'
            {
                "id": "form-1",
                "fields": [
                    {"type": "text", "name": "email", "maxLength": 120},
                    {"type": "select", "name": "country", "options": ["pl", "de"]}
                ]
            }
            JSON);

        // WHEN
        $form = $mapper->map(FormDefinition::class, $source);

        // THEN
        self::assertInstanceOf(TextField::class, $form->fields[0]);
        self::assertSame(120, $form->fields[0]->maxLength);
        self::assertInstanceOf(SelectField::class, $form->fields[1]);
        self::assertSame(['pl', 'de'], $form->fields[1]->options);
    }

    public function testUnknownVariantIsReportedWithKnownVariants(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(Field::class, Source::json('{"type": "webhook", "name": "x"}'));

        // THEN
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.unknown_variant', $error->code);
        self::assertSame('/type', $error->pointer->toString());
        self::assertSame('webhook', $error->input);
        self::assertStringContainsString('"text"', $error->message);
    }

    public function testMissingDiscriminatorFieldIsReported(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(Field::class, Source::json('{"name": "x"}'));

        // THEN
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.discriminator.missing', $error->code);
        self::assertSame('', $error->pointer->toString());
    }

    public function testOpenUnionVariantRegisteredOnTheBuilderWins(): void
    {
        // GIVEN a plugin-registered variant
        $mapper = MapperBuilder::create()
            ->withVariant(Field::class, 'custom', CustomField::class)
            ->build();

        // WHEN
        $field = $mapper->map(Field::class, Source::json('{"type": "custom", "name": "x", "custom": "extra"}'));

        // THEN
        self::assertInstanceOf(CustomField::class, $field);
        self::assertSame('extra', $field->custom);
    }

    public function testUnknownVariantHydratesTheFallbackPreservingPayload(): void
    {
        // GIVEN a lenient (editor-style) mapper
        $mapper = MapperBuilder::create()
            ->withVariantFallback(Field::class, GenericField::class)
            ->build();

        // WHEN
        $field = $mapper->map(Field::class, Source::json('{"type": "webhook", "name": "hook", "url": "https://x"}'));

        // THEN the raw payload survives for round-tripping
        self::assertInstanceOf(GenericField::class, $field);
        self::assertSame('webhook', $field->type);
        self::assertSame('hook', $field->name);
        self::assertSame(['url' => 'https://x'], $field->extras);
    }

    public function testSchemaBoundToClassRunsAsPreCheck(): void
    {
        // GIVEN a schema requiring "age" to be >= 0
        $schema = Schema::fromJson('{"type": "object", "properties": {"age": {"minimum": 0}}}');
        $mapper = MapperBuilder::create()->withSchema(Person::class, $schema)->build();

        // WHEN
        $result = $mapper->tryMap(Person::class, Source::json('{"name": "Ada", "age": -1, "address": {"street": "s", "city": "c"}}'));

        // THEN schema errors gate the mapping
        self::assertFalse($result->isSuccess());
        $error = $result->errors()->errors[0];
        self::assertSame('schema.minimum', $error->code);
        self::assertSame('/age', $error->pointer->toString());
    }

    public function testSourceSchemaOverrideWinsOverTheVault(): void
    {
        // GIVEN the vault would accept the document, the override rejects everything
        $mapper = MapperBuilder::create()
            ->withSchema(Address::class, Schema::fromDocument(true))
            ->build();
        $source = Source::json('{"street": "s", "city": "c"}')->withSchema(Schema::fromDocument(false));

        // WHEN
        $result = $mapper->tryMap(Address::class, $source);

        // THEN
        self::assertFalse($result->isSuccess());
    }

    public function testNodeValidatorRunsForEveryInstanceAndSeesTheRoot(): void
    {
        // GIVEN a validator bound to a nested class
        $validator = new AddressCityValidator();
        $mapper = MapperBuilder::create()->withValidator(Address::class, $validator)->build();

        // WHEN
        $result = $mapper->tryMap(Person::class, Source::json('{"name": "Ada", "age": 1, "address": {"street": "s", "city": ""}}'));

        // THEN the error path is absolute and the validator saw the document root
        self::assertFalse($result->isSuccess());
        $error = $result->errors()->errors[0];
        self::assertSame('address.city.empty', $error->code);
        self::assertSame('/address/city', $error->pointer->toString());
        self::assertInstanceOf(Person::class, $validator->observedRoot);
    }

    public function testRootValidatorReportsSemanticErrors(): void
    {
        // GIVEN duplicate field names, structurally valid
        $mapper = MapperBuilder::create()
            ->withValidator(FormDefinition::class, new UniqueFieldNamesValidator())
            ->build();
        $source = Source::json(<<<'JSON'
            {
                "id": "form-1",
                "fields": [
                    {"type": "text", "name": "email"},
                    {"type": "text", "name": "email"}
                ]
            }
            JSON);

        // WHEN
        $result = $mapper->tryMap(FormDefinition::class, $source);

        // THEN
        self::assertFalse($result->isSuccess());
        $error = $result->errors()->errors[0];
        self::assertSame('form.field.duplicate-name', $error->code);
        self::assertSame('/fields/1/name', $error->pointer->toString());
    }

    public function testValidatorFactoryResolvesLazily(): void
    {
        // GIVEN
        $resolved = 0;
        $mapper = MapperBuilder::create()
            ->withValidatorFactory(FormDefinition::class, static function () use (&$resolved): UniqueFieldNamesValidator {
                ++$resolved;

                return new UniqueFieldNamesValidator();
            })
            ->build();

        // WHEN nothing matching is mapped yet
        $mapper->map(Address::class, Source::json('{"street": "s", "city": "c"}'));

        // THEN
        self::assertSame(0, $resolved);
    }

    public function testConstructorInvariantViolationBecomesAMappingError(): void
    {
        // GIVEN a class guarding its invariant in the constructor
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(Amount::class, Source::json('{"cents": -5}'));

        // THEN "parse, don't validate" — the domain exception joins the report
        self::assertFalse($result->isSuccess());
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.constructor', $error->code);
        self::assertSame('Amount cannot be negative.', $error->message);
    }

    public function testMalformedJsonBecomesASourceError(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(Address::class, Source::json('{broken'));

        // THEN
        self::assertFalse($result->isSuccess());
        self::assertSame('source.malformed_json', $result->errors()->errors[0]->code);
    }

    public function testMapThrowsMappingFailedWithTheFullReport(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // THEN
        $this->expectException(MappingFailed::class);
        $this->expectExceptionMessage('mapping.missing_key');

        // WHEN
        $mapper->map(Address::class, Source::json('{}'));
    }

    public function testMapsTypeStringTargets(): void
    {
        // GIVEN a non-class target
        $mapper = MapperBuilder::create()->build();

        // WHEN
        /** @var list<Address> $addresses */
        $addresses = $mapper->map('list<' . Address::class . '>', Source::json('[{"street": "a", "city": "b"}, {"street": "c", "city": "d"}]'));

        // THEN
        self::assertCount(2, $addresses);
        self::assertSame('d', $addresses[1]->city);
    }

    public function testMapsMapTargets(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        /** @var array<string, int> $scores */
        $scores = $mapper->map('array<string, int>', Source::json('{"ada": 10, "grace": 9}'));

        // THEN
        self::assertSame(['ada' => 10, 'grace' => 9], $scores);
    }

    public function testMapsAlreadyDecodedArraySource(): void
    {
        // GIVEN input decoded elsewhere (framework middleware)
        $mapper = MapperBuilder::create()->build();
        $decoded = (object) ['street' => 's', 'city' => 'c'];

        // WHEN
        $address = $mapper->map(Address::class, Source::array($decoded));

        // THEN
        self::assertSame('c', $address->city);
    }

    public function testListErrorsCarryItemIndexesInPointers(): void
    {
        // GIVEN one bad item among good ones
        $mapper = MapperBuilder::create()->build();
        $source = Source::json('[{"street": "a", "city": "b"}, {"street": 7, "city": "d"}]');

        // WHEN
        $result = $mapper->tryMap('list<' . Address::class . '>', $source);

        // THEN
        $error = $result->errors()->errors[0];
        self::assertSame('/1/street', $error->pointer->toString());
        self::assertSame('mapping.type', $error->code);
    }

    public function testNonArrayInputForListTargetIsATypeError(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap('list<int>', Source::json('{"not": "a list"}'));

        // THEN exactly one error, at the list itself — items are never visited
        self::assertCount(1, $result->errors());
        self::assertSame('mapping.type', $result->errors()->errors[0]->code);
        self::assertSame('', $result->errors()->errors[0]->pointer->toString());
    }

    public function testSchemaVaultIsNotConsultedForTypeStringTargets(): void
    {
        // GIVEN a spy vault
        $vault = new \JsonMine\Tests\Fixture\RecordingSchemaVault();
        $mapper = MapperBuilder::create()->withSchemaVault($vault)->build();

        // WHEN
        $mapper->map('list<int>', Source::json('[1]'));
        $mapper->map(Address::class, Source::json('{"street": "s", "city": "c"}'));

        // THEN only the class target reached the vault
        self::assertSame([Address::class], $vault->asked);
    }
}
