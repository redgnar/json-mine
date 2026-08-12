<?php

declare(strict_types=1);

namespace Ingot\Tests\Schema;

use Ingot\Schema\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Schema::class)]
final class SchemaTest extends TestCase
{
    public function testCreatesSchemaFromJsonObject(): void
    {
        // GIVEN
        $json = '{"type": "object", "required": ["id"]}';

        // WHEN
        $schema = Schema::fromJson($json, 'form-1.0.json');

        // THEN
        self::assertInstanceOf(\stdClass::class, $schema->document);
        self::assertSame('object', $schema->document->type);
        self::assertSame('form-1.0.json', $schema->uri);
    }

    public function testAcceptsBooleanSchema(): void
    {
        // GIVEN the JSON Schema spec allows boolean schemas
        $json = 'true';

        // WHEN
        $schema = Schema::fromJson($json);

        // THEN
        self::assertTrue($schema->document);
        self::assertNull($schema->uri);
    }

    public function testRejectsJsonThatIsNotAnObjectOrBoolean(): void
    {
        // GIVEN
        $json = '42';

        // THEN
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an object or a boolean');

        // WHEN
        Schema::fromJson($json);
    }

    public function testRejectsMalformedJson(): void
    {
        // GIVEN
        $json = '{broken';

        // THEN
        $this->expectException(\JsonException::class);

        // WHEN
        Schema::fromJson($json);
    }

    public function testLoadsSchemaFromFileAndUsesPathAsUri(): void
    {
        // GIVEN
        $path = __DIR__ . '/../fixtures/schema.json';

        // WHEN
        $schema = Schema::fromFile($path);

        // THEN
        self::assertInstanceOf(\stdClass::class, $schema->document);
        self::assertSame(['id'], $schema->document->required);
        self::assertSame($path, $schema->uri);
    }

    public function testRejectsMissingSchemaFile(): void
    {
        // GIVEN
        $path = __DIR__ . '/../fixtures/no-such-schema.json';

        // THEN
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        // WHEN
        Schema::fromFile($path);
    }

    public function testWrapsAnExistingDocument(): void
    {
        // GIVEN
        $document = new \stdClass();
        $document->type = 'string';

        // WHEN
        $schema = Schema::fromDocument($document, 'inline');

        // THEN
        self::assertSame($document, $schema->document);
        self::assertSame('inline', $schema->uri);
    }
}
