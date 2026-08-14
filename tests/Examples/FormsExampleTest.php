<?php

declare(strict_types=1);

namespace Ingot\Tests\Examples;

use Ingot\Error\MappingFailed;
use Ingot\Examples\Forms\Definition\FormDefinition;
use Ingot\Examples\Forms\Definition\GenericField;
use Ingot\Examples\Forms\Definition\TextField;
use Ingot\Examples\Forms\FormProcessor;
use Ingot\SchemaGen\SchemaGenerator;
use Ingot\Source;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end run of the examples/Forms pipeline — living documentation:
 * definition (meta-schema + semantic rules) → derived data schema →
 * submission validation → typed value access → lossless save.
 */
final class FormsExampleTest extends TestCase
{
    private const string EXAMPLE_FORM = __DIR__ . '/../../examples/Forms/example-form.json';

    public function testLoadsTheExampleDefinitionIncludingAnUnknownFieldType(): void
    {
        // GIVEN
        $processor = new FormProcessor();

        // WHEN
        $definition = $processor->loadDefinition(Source::file(self::EXAMPLE_FORM));

        // THEN
        self::assertSame('contact', $definition->id);
        self::assertCount(4, $definition->fields);
        self::assertInstanceOf(TextField::class, $definition->fields[0]);
        self::assertSame(120, $definition->fields[0]->maxLength);
        // the unknown "signature" type fell back to GenericField, payload preserved
        self::assertInstanceOf(GenericField::class, $definition->fields[3]);
        self::assertSame('signature', $definition->fields[3]->type);
        self::assertInstanceOf(\stdClass::class, $definition->fields[3]->extras['vendor']);
    }

    public function testDerivedDataSchemaReflectsTheDefinition(): void
    {
        // GIVEN
        $processor = new FormProcessor();
        $definition = $processor->loadDefinition(Source::file(self::EXAMPLE_FORM));

        // WHEN
        $schema = $processor->dataSchema($definition);
        $document = json_decode(json_encode($schema->document, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        // THEN required fields, constraints, and enums came from the definition
        self::assertSame(['email', 'country'], $document['required']);
        self::assertIsArray($document['properties']);
        self::assertSame(
            ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
            $document['properties']['email'],
        );
        self::assertSame(['enum' => ['pl', 'de', 'fr']], $document['properties']['country']);
        self::assertSame(['type' => 'number', 'minimum' => 18, 'maximum' => 120], $document['properties']['age']);
        // unknown field types accept anything — their plugin owns the contract
        self::assertSame([], $document['properties']['sig']);
        self::assertFalse($document['additionalProperties']);
    }

    public function testValidSubmissionProducesAnEmptyReport(): void
    {
        // GIVEN
        $processor = new FormProcessor();
        $definition = $processor->loadDefinition(Source::file(self::EXAMPLE_FORM));
        $submission = Source::json('{"email": "ada@example.com", "country": "pl", "age": 36, "sig": {"strokes": []}}');

        // WHEN
        $report = $processor->validateData($definition, $submission);

        // THEN
        self::assertTrue($report->isEmpty());
    }

    public function testInvalidSubmissionReportsEveryProblemWithPointers(): void
    {
        // GIVEN a submission violating several field constraints at once
        $processor = new FormProcessor();
        $definition = $processor->loadDefinition(Source::file(self::EXAMPLE_FORM));
        $submission = Source::json(\sprintf(
            '{"email": "%s", "country": "us", "age": 5, "bogus": 1}',
            str_repeat('x', 130),
        ));

        // WHEN
        $report = $processor->validateData($definition, $submission);

        // THEN every violation is present, each at its exact location
        $byPointer = [];
        $messages = [];

        foreach ($report as $error) {
            $byPointer[$error->pointer->toString()][] = $error->code;
            $messages[] = $error->message;
        }

        self::assertContains('schema.maxLength', $byPointer['/email']);
        self::assertContains('schema.enum', $byPointer['/country']);
        self::assertContains('schema.minimum', $byPointer['/age']);
        // the unexpected key is reported at the owning object, named in the message
        self::assertContains('schema.additionalProperties', $byPointer['']);
        self::assertStringContainsString('bogus', implode("\n", $messages));
    }

    public function testMissingRequiredFieldIsRejected(): void
    {
        // GIVEN "email" is required by the definition
        $processor = new FormProcessor();
        $definition = $processor->loadDefinition(Source::file(self::EXAMPLE_FORM));

        // WHEN
        $report = $processor->validateData($definition, Source::json('{"country": "pl"}'));

        // THEN
        self::assertFalse($report->isEmpty());
        self::assertSame('schema.required', $report->errors[0]->code);
    }

    public function testMalformedSubmissionIsASourceError(): void
    {
        // GIVEN
        $processor = new FormProcessor();
        $definition = $processor->loadDefinition(Source::file(self::EXAMPLE_FORM));

        // WHEN
        $report = $processor->validateData($definition, Source::json('{broken'));

        // THEN
        self::assertSame('source.malformed_json', $report->errors[0]->code);
    }

    public function testValuesAreReadableThroughJsonNode(): void
    {
        // GIVEN a validated submission (form data has no classes)
        $processor = new FormProcessor();
        $submission = Source::json('{"email": "ada@example.com", "country": "pl", "age": 36}');

        // WHEN
        $values = $processor->values($submission);

        // THEN
        self::assertSame('ada@example.com', $values->get('/email')->string());
        self::assertSame(36.0, $values->get('/age')->float());
        self::assertFalse($values->exists('/nickname'));
    }

    public function testDefinitionWithDuplicateFieldNamesIsRejected(): void
    {
        // GIVEN a structurally valid definition breaking a semantic rule
        $processor = new FormProcessor();
        $json = <<<'JSON'
            {
                "id": "dup",
                "title": "Duplicates",
                "fields": [
                    {"type": "text", "name": "email"},
                    {"type": "text", "name": "email"}
                ]
            }
            JSON;

        // WHEN
        try {
            $processor->loadDefinition(Source::json($json));
            self::fail('Expected MappingFailed.');
        } catch (MappingFailed $exception) {
            // THEN
            $error = $exception->report()->errors[0];
            self::assertSame('form.field.duplicate-name', $error->code);
            self::assertSame('/fields/1/name', $error->pointer->toString());
        }
    }

    public function testDefinitionViolatingFieldConstraintsIsRejected(): void
    {
        // GIVEN a definition the meta-schema accepts — field payloads live
        // inside the open union it deliberately leaves loose — but the
        // #[Constraints] on the model reject: duplicated select options and
        // a zero length limit
        $processor = new FormProcessor();
        $json = <<<'JSON'
            {
                "id": "broken",
                "title": "Broken",
                "fields": [
                    {"type": "select", "name": "country", "options": ["pl", "pl"]},
                    {"type": "text", "name": "note", "maxLength": 0}
                ]
            }
            JSON;

        // WHEN
        try {
            $processor->loadDefinition(Source::json($json));
            self::fail('Expected MappingFailed.');
        } catch (MappingFailed $exception) {
            // THEN every violation reports at its exact location
            $codes = [];

            foreach ($exception->report() as $error) {
                $codes[$error->pointer->toString()][] = $error->code;
            }

            self::assertContains('mapping.unique_items', $codes['/fields/0/options/1']);
            self::assertContains('mapping.exclusive_minimum', $codes['/fields/1/maxLength']);
        }
    }

    public function testDefinitionWithABadIdFailsTheMetaSchemaPreCheck(): void
    {
        // GIVEN the hand-written meta-schema states the same id pattern the
        // #[Constraints] attribute does — a top-level violation is caught by
        // the schema pre-check before any mapping runs
        $processor = new FormProcessor();

        // WHEN
        try {
            $processor->loadDefinition(Source::json('{"id": "Bad_Id", "title": "x", "fields": [{"type": "text", "name": "a"}]}'));
            self::fail('Expected MappingFailed.');
        } catch (MappingFailed $exception) {
            // THEN
            $error = $exception->report()->errors[0];
            self::assertSame('schema.pattern', $error->code);
            self::assertSame('/id', $error->pointer->toString());
        }
    }

    public function testGeneratedDefinitionSchemaCarriesTheConstraints(): void
    {
        // GIVEN the definition model with its #[Constraints] attributes
        $schema = new SchemaGenerator()->generate(FormDefinition::class);

        // WHEN
        $document = json_decode(json_encode($schema->document, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $defs = $document['$defs'];
        self::assertIsArray($defs);

        // THEN every constraint keyword lands in the generated JSON Schema —
        // read from the same metadata the mapper enforces, so no drift
        $definition = self::properties($defs['Ingot.Examples.Forms.Definition.FormDefinition'] ?? null);
        self::assertSame(
            ['type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[a-z][a-z0-9-]*$'],
            $definition['id'],
        );
        self::assertSame(
            ['type' => 'array', 'items' => ['$ref' => '#/$defs/Ingot.Examples.Forms.Definition.Field'], 'minItems' => 1, 'maxItems' => 50],
            $definition['fields'],
        );

        $select = self::properties($defs['Ingot.Examples.Forms.Definition.SelectField'] ?? null);
        self::assertSame(
            ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'uniqueItems' => true],
            $select['options'],
        );

        $text = self::properties($defs['Ingot.Examples.Forms.Definition.TextField'] ?? null);
        self::assertSame(
            ['anyOf' => [['type' => 'integer', 'exclusiveMinimum' => 0], ['type' => 'null']]],
            $text['maxLength'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function properties(mixed $def): array
    {
        self::assertIsArray($def);
        self::assertIsArray($def['properties'] ?? null);

        /** @var array<string, mixed> */
        return $def['properties'];
    }

    public function testDefinitionViolatingTheMetaSchemaIsRejected(): void
    {
        // GIVEN a definition missing its required "title"
        $processor = new FormProcessor();

        // WHEN
        try {
            $processor->loadDefinition(Source::json('{"id": "x", "fields": []}'));
            self::fail('Expected MappingFailed.');
        } catch (MappingFailed $exception) {
            // THEN
            self::assertSame('schema.required', $exception->report()->errors[0]->code);
        }
    }

    public function testDefinitionRoundTripsLosslesslyIncludingUnknownFields(): void
    {
        // GIVEN
        $processor = new FormProcessor();
        $original = file_get_contents(self::EXAMPLE_FORM);
        self::assertIsString($original);

        // WHEN load → save
        $saved = $processor->saveDefinition($processor->loadDefinition(Source::json($original)));

        // THEN nothing was lost — not even the unknown "signature" field
        self::assertEquals(
            json_decode($original, true, flags: \JSON_THROW_ON_ERROR),
            json_decode($saved, true, flags: \JSON_THROW_ON_ERROR),
        );
    }
}
