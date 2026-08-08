<?php

declare(strict_types=1);

namespace JsonMine\Tests;

use JsonMine\Schema\Schema;
use JsonMine\Source;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Source::class)]
final class SourceTest extends TestCase
{
    public function testDecodesJsonObjectsToStdClass(): void
    {
        // GIVEN
        $source = Source::json('{"name": "email", "position": [1, 2]}');

        // WHEN
        $data = $source->data();

        // THEN objects stay \stdClass so the JSON object-vs-array distinction survives
        self::assertInstanceOf(\stdClass::class, $data);
        self::assertSame('email', $data->name);
        self::assertSame([1, 2], $data->position);
    }

    public function testReturnsAlreadyDecodedInputAsIs(): void
    {
        // GIVEN
        $decoded = ['name' => 'email'];
        $source = Source::array($decoded);

        // WHEN
        $data = $source->data();

        // THEN
        self::assertSame($decoded, $data);
    }

    public function testReadsJsonFromFile(): void
    {
        // GIVEN
        $source = Source::file(__DIR__ . '/fixtures/document.json');

        // WHEN
        $data = $source->data();

        // THEN
        self::assertInstanceOf(\stdClass::class, $data);
        self::assertSame('form-1', $data->id);
    }

    public function testRejectsMissingFileAtConstructionTime(): void
    {
        // GIVEN
        $path = __DIR__ . '/fixtures/does-not-exist.json';

        // THEN
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        // WHEN
        Source::file($path);
    }

    public function testMalformedJsonSurfacesWhenDataIsRead(): void
    {
        // GIVEN construction succeeds even for malformed input (parse errors belong to the mapping report)
        $source = Source::json('{broken');

        // THEN
        $this->expectException(\JsonException::class);

        // WHEN
        $source->data();
    }

    public function testHasNoSchemaOverrideByDefault(): void
    {
        // GIVEN
        $source = Source::json('{}');

        // WHEN
        $override = $source->schemaOverride;

        // THEN
        self::assertNull($override);
    }

    public function testWithSchemaReturnsNewInstanceAndKeepsOriginalIntact(): void
    {
        // GIVEN
        $original = Source::json('{"id": "form-1"}');
        $schema = Schema::fromDocument(true);

        // WHEN
        $overridden = $original->withSchema($schema);

        // THEN
        self::assertNull($original->schemaOverride);
        self::assertSame($schema, $overridden->schemaOverride);
        self::assertEquals($original->data(), $overridden->data());
    }
}
