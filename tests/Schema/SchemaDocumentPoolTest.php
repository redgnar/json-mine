<?php

declare(strict_types=1);

namespace JsonMine\Tests\Schema;

use JsonMine\Schema\Schema;
use JsonMine\Schema\SchemaDocumentPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaDocumentPool::class)]
final class SchemaDocumentPoolTest extends TestCase
{
    public function testContentIdenticalSchemasShareOneCanonicalDocument(): void
    {
        // GIVEN two fresh Schema instances with identical content (per-request construction)
        $pool = new SchemaDocumentPool();
        $json = '{"type": "object", "required": ["id"]}';
        $first = Schema::fromJson($json);
        $second = Schema::fromJson($json);
        self::assertNotSame($first->document, $second->document);

        // WHEN
        $canonicalFirst = $pool->canonical($first);
        $canonicalSecond = $pool->canonical($second);

        // THEN both resolve to the very same instance — opis parses it once
        self::assertSame($canonicalFirst, $canonicalSecond);
        self::assertSame($first->document, $canonicalFirst);
    }

    public function testDifferentContentKeepsDistinctDocuments(): void
    {
        // GIVEN
        $pool = new SchemaDocumentPool();
        $first = Schema::fromJson('{"type": "object"}');
        $second = Schema::fromJson('{"type": "array"}');

        // WHEN / THEN
        self::assertNotSame($pool->canonical($first), $pool->canonical($second));
    }

    public function testBooleanSchemasPassThrough(): void
    {
        // GIVEN
        $pool = new SchemaDocumentPool();

        // WHEN / THEN
        self::assertTrue($pool->canonical(Schema::fromDocument(true)));
        self::assertFalse($pool->canonical(Schema::fromDocument(false)));
    }
}
