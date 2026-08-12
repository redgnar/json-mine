<?php

declare(strict_types=1);

namespace Ingot\Tests\SchemaGen;

use Ingot\MapperBuilder;
use Ingot\Mapping\Metadata\MetadataFactory;
use Ingot\Mapping\Type\TypeParser;
use Ingot\Mapping\VariantRegistry;
use Ingot\Schema\OpisSchemaValidator;
use Ingot\Schema\Schema;
use Ingot\SchemaGen\SchemaGenerator;
use Ingot\Source;
use Ingot\Tests\Fixture\Color;
use Ingot\Tests\Fixture\CustomField;
use Ingot\Tests\Fixture\Field;
use Ingot\Tests\Fixture\FormDefinition;
use Ingot\Tests\Fixture\Person;
use Ingot\Tests\Fixture\PropsWithExtras;
use Ingot\Tests\Fixture\TreeNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaGenerator::class)]
final class SchemaGeneratorTest extends TestCase
{
    public function testGeneratesFragmentsForTypeStringTargets(): void
    {
        // GIVEN
        $generator = new SchemaGenerator();

        // WHEN / THEN
        self::assertSame(
            ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'type' => 'array', 'items' => ['type' => 'integer']],
            $this->doc($generator->generate('list<int>')),
        );
        self::assertSame(
            ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'type' => 'object', 'additionalProperties' => ['type' => 'number']],
            $this->doc($generator->generate('array<string, float>')),
        );
        self::assertSame(
            ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'type' => 'object', 'additionalProperties' => true],
            $this->doc($generator->generate('array<string, mixed>')),
        );
    }

    public function testBooleanScalarFragment(): void
    {
        // GIVEN / WHEN
        $document = $this->doc(new SchemaGenerator()->generate('list<bool>'));

        // THEN
        self::assertSame(['type' => 'boolean'], $document['items'] ?? null);
    }

    public function testPropertyHydratedClassContributesItsMembers(): void
    {
        // GIVEN a class hydrated exclusively through properties
        $document = $this->doc(new SchemaGenerator()->generate(\Ingot\Tests\Fixture\PropsOnly::class));

        // WHEN
        $def = $this->def($document, 'Ingot.Tests.Fixture.PropsOnly');

        // THEN static members are excluded; required = no default, not nullable
        self::assertSame(
            ['id', 'name', 'count', 'note', 'status', 'nums'],
            array_keys(self::arr($def['properties'])),
        );
        self::assertSame(['id', 'name', 'count'], $def['required']);
    }

    public function testUnionRootWithoutVariantsIsAPlainObjectSchema(): void
    {
        // GIVEN a #[Discriminator] root whose variants come from plugins only,
        // and no plugin registered any
        $document = $this->doc(new SchemaGenerator()->generate(\Ingot\Tests\Fixture\Widget::class));

        // WHEN
        $def = $this->def($document, 'Ingot.Tests.Fixture.Widget');

        // THEN no anyOf materializes out of thin air
        self::assertSame('object', $def['type'] ?? null);
        self::assertArrayNotHasKey('anyOf', $def);
    }

    public function testMixedTargetIsAnAcceptEverythingSchema(): void
    {
        // GIVEN / WHEN
        $document = $this->doc(new SchemaGenerator()->generate('mixed'));

        // THEN
        self::assertSame(['$schema' => 'https://json-schema.org/draft/2020-12/schema'], $document);
    }

    public function testNullableBecomesAnyOfWithNull(): void
    {
        // GIVEN / WHEN
        $document = $this->doc(new SchemaGenerator()->generate('list<?string>'));

        // THEN
        self::assertSame(['anyOf' => [['type' => 'string'], ['type' => 'null']]], $document['items'] ?? null);
    }

    public function testBackedEnumBecomesAnEnumSchema(): void
    {
        // GIVEN / WHEN
        $document = $this->doc(new SchemaGenerator()->generate(Color::class));

        // THEN
        self::assertSame(['red', 'blue'], $document['enum'] ?? null);
    }

    public function testDateTimeBecomesAFormattedString(): void
    {
        // GIVEN / WHEN
        $document = $this->doc(new SchemaGenerator()->generate(\DateTimeImmutable::class));

        // THEN
        self::assertSame('string', $document['type'] ?? null);
        self::assertSame('date-time', $document['format'] ?? null);
    }

    public function testClassBecomesARefWithADefinition(): void
    {
        // GIVEN
        $generator = new SchemaGenerator();

        // WHEN
        $document = $this->doc($generator->generate(Person::class));

        // THEN the root references the Person definition
        self::assertSame('#/$defs/Ingot.Tests.Fixture.Person', $document['$ref']);

        $person = $this->def($document, 'Ingot.Tests.Fixture.Person');
        self::assertSame('object', $person['type']);
        // required = no default and not nullable; tags/nickname have defaults
        self::assertSame(['name', 'age', 'address'], $person['required']);
        self::assertSame(['$ref' => '#/$defs/Ingot.Tests.Fixture.Address'], self::arr($person['properties'])['address']);
        self::assertFalse($person['additionalProperties']);

        $address = $this->def($document, 'Ingot.Tests.Fixture.Address');
        self::assertSame(['street', 'city'], $address['required']);
    }

    public function testExtrasBagOpensAdditionalProperties(): void
    {
        // GIVEN / WHEN
        $document = $this->doc(new SchemaGenerator()->generate(PropsWithExtras::class));

        // THEN unknown keys are legal for classes with an #[Extras] bag,
        // and members declared after the bag still contribute
        $def = $this->def($document, 'Ingot.Tests.Fixture.PropsWithExtras');
        self::assertTrue($def['additionalProperties']);
        self::assertSame(['id', 'created_at', 'meta'], array_keys(self::arr($def['properties'])));
    }

    public function testDiscriminatedUnionBecomesAnyOfWithConstDiscriminators(): void
    {
        // GIVEN a registry variant on top of the closed map
        $variants = new VariantRegistry();
        $variants->register(Field::class, 'custom', CustomField::class);
        $generator = new SchemaGenerator(new MetadataFactory(), new TypeParser(), $variants);

        // WHEN
        $document = $this->doc($generator->generate(Field::class));

        // THEN the root is an anyOf over all three variants…
        $union = $this->def($document, 'Ingot.Tests.Fixture.Field');
        self::assertCount(3, self::arr($union['anyOf']));

        // …and each variant requires its discriminator as a const
        $text = $this->def($document, 'Ingot.Tests.Fixture.TextField');
        self::assertSame(['const' => 'text'], self::arr($text['properties'])['type']);
        self::assertSame(['type', 'name'], $text['required']);

        $custom = $this->def($document, 'Ingot.Tests.Fixture.CustomField');
        self::assertSame(['const' => 'custom'], self::arr($custom['properties'])['type']);

        // the discriminator prepends — every original required member survives
        $select = $this->def($document, 'Ingot.Tests.Fixture.SelectField');
        self::assertSame(['type', 'name', 'options'], $select['required']);
    }

    public function testRecursiveClassesGenerateSelfReferencingDefs(): void
    {
        // GIVEN / WHEN
        $document = $this->doc(new SchemaGenerator()->generate(TreeNode::class));

        // THEN
        $node = $this->def($document, 'Ingot.Tests.Fixture.TreeNode');
        self::assertSame(
            ['type' => 'array', 'items' => ['$ref' => '#/$defs/Ingot.Tests.Fixture.TreeNode']],
            self::arr($node['properties'])['children'],
        );
    }

    public function testNormalizedDataValidatesAgainstTheGeneratedSchema(): void
    {
        // GIVEN a document that went through map → normalize
        $mapper = MapperBuilder::create()->build();
        $json = <<<'JSON'
            {
                "id": "form-1",
                "fields": [
                    {"type": "text", "name": "email", "maxLength": 120},
                    {"type": "select", "name": "country", "options": ["pl", "de"]}
                ]
            }
            JSON;
        $normalized = $mapper->normalize($mapper->map(FormDefinition::class, Source::json($json)));
        $schema = new SchemaGenerator()->generate(FormDefinition::class);

        // WHEN the same document is checked against the generated schema
        $report = new OpisSchemaValidator()->validate(
            json_decode(json_encode($normalized, \JSON_THROW_ON_ERROR)),
            $schema,
        );

        // THEN generator, hydrator, and normalizer agree
        self::assertTrue($report->isEmpty());
    }

    public function testUnknownVariantIsInvalidAgainstTheGeneratedSchema(): void
    {
        // GIVEN
        $schema = new SchemaGenerator()->generate(Field::class);

        // WHEN
        $report = new OpisSchemaValidator()->validate(
            json_decode('{"type": "webhook", "name": "hook"}'),
            $schema,
        );

        // THEN like a strict engine, the schema rejects unknown variants
        self::assertFalse($report->isEmpty());
    }

    /**
     * @return array<string, mixed>
     */
    private function doc(Schema $schema): array
    {
        $decoded = json_decode(json_encode($schema->document, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        /** @var array<string, mixed> $decoded schema documents are JSON objects */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function def(array $document, string $name): array
    {
        return self::arr(self::arr($document['$defs'] ?? null)[$name] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }
}
