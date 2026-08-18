<?php

declare(strict_types=1);

namespace Ingot\Tests\Schema;

use Ingot\Schema\OpisSchemaValidator;
use Ingot\Schema\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpisSchemaValidator::class)]
final class OpisSchemaValidatorTest extends TestCase
{
    public function testValidDocumentProducesAnEmptyReport(): void
    {
        // GIVEN
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "object", "required": ["id"], "properties": {"id": {"type": "string"}}}');
        $document = $this->decode('{"id": "form-1"}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN
        self::assertTrue($report->isEmpty());
    }

    public function testMissingRequiredPropertyIsReportedAtTheOwningObject(): void
    {
        // GIVEN
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "object", "required": ["id"]}');
        $document = $this->decode('{}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN
        self::assertCount(1, $report);
        $error = $report->errors[0];
        self::assertSame('schema.required', $error->code);
        self::assertSame('', $error->pointer->toString());
    }

    public function testNestedTypeViolationCarriesTheFullJsonPointer(): void
    {
        // GIVEN
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(<<<'JSON'
            {
                "type": "object",
                "properties": {
                    "fields": {
                        "type": "array",
                        "items": {
                            "type": "object",
                            "properties": {"name": {"type": "string"}}
                        }
                    }
                }
            }
            JSON);
        $document = $this->decode('{"fields": [{"name": "ok"}, {"name": 42}]}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN
        self::assertCount(1, $report);
        $error = $report->errors[0];
        self::assertSame('schema.type', $error->code);
        self::assertSame('/fields/1/name', $error->pointer->toString());
        self::assertSame(42, $error->input);
    }

    public function testCollectsMultipleErrorsInOneReport(): void
    {
        // GIVEN
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(<<<'JSON'
            {
                "type": "object",
                "required": ["id"],
                "properties": {
                    "title": {"type": "string"},
                    "count": {"type": "integer"}
                }
            }
            JSON);
        $document = $this->decode('{"title": 7, "count": "many"}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN all problems are aggregated, not just the first one
        $codes = array_map(static fn($error): string => $error->code, $report->errors);
        sort($codes);
        self::assertSame(['schema.required', 'schema.type', 'schema.type'], $codes);
    }

    public function testAnUnexpectedMemberIsReportedWhereItSits(): void
    {
        // GIVEN a closed object schema
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "object", "properties": {"id": {"type": "string"}}, "additionalProperties": false}');
        $document = $this->decode('{"id": "form-1", "bogus": 1, "other": 2}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN each unexpected member is named at its own pointer, carrying the
        // value that was not asked for — not one lump on the owning object
        self::assertCount(2, $report);
        self::assertSame('/bogus', $report->errors[0]->pointer->toString());
        self::assertSame('schema.additionalProperties', $report->errors[0]->code);
        self::assertSame(1, $report->errors[0]->input);
        self::assertSame('/other', $report->errors[1]->pointer->toString());
    }

    public function testAMemberThatBrokeItsOwnRuleIsNotAlsoCalledUnexpected(): void
    {
        // GIVEN a declared member with a value its subschema refuses. opis
        // counts a failed member as one it never evaluated, so it appears in
        // the additionalProperties list as well.
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "object", "properties": {"age": {"type": "number", "minimum": 18}}, "additionalProperties": false}');
        $document = $this->decode('{"age": 7}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN the report names the rule that was broken, and nothing else
        self::assertCount(1, $report);
        self::assertSame('/age', $report->errors[0]->pointer->toString());
        self::assertSame('schema.minimum', $report->errors[0]->code);
    }

    public function testUnexpectedMembersOfANestedObjectKeepTheirFullPointer(): void
    {
        // GIVEN a closed object nested inside another
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(<<<'JSON'
            {
                "type": "object",
                "properties": {
                    "author": {
                        "type": "object",
                        "properties": {"name": {"type": "string"}},
                        "additionalProperties": false
                    }
                }
            }
            JSON);
        $document = $this->decode('{"author": {"name": "Ada", "nickname": "the countess"}}');

        // WHEN
        $report = $validator->validate($document, $schema);

        // THEN
        self::assertCount(1, $report);
        self::assertSame('/author/nickname', $report->errors[0]->pointer->toString());
        self::assertSame('schema.additionalProperties', $report->errors[0]->code);
    }

    public function testBooleanFalseSchemaRejectsEverything(): void
    {
        // GIVEN
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromDocument(false);

        // WHEN
        $report = $validator->validate($this->decode('{"any": "thing"}'), $schema);

        // THEN
        self::assertFalse($report->isEmpty());
    }

    public function testBooleanTrueSchemaAcceptsEverything(): void
    {
        // GIVEN
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromDocument(true);

        // WHEN
        $report = $validator->validate($this->decode('{"any": "thing"}'), $schema);

        // THEN
        self::assertTrue($report->isEmpty());
    }

    private function decode(string $json): mixed
    {
        return json_decode($json, false, flags: \JSON_THROW_ON_ERROR);
    }
}
