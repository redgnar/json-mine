<?php

declare(strict_types=1);

namespace Ingot\Tests\Tree;

use Ingot\Error\MappingFailed;
use Ingot\Source;
use Ingot\Tree\JsonNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonNode::class)]
final class JsonNodeTest extends TestCase
{
    private const string DOCUMENT = <<<'JSON'
        {
            "customer": {
                "name": "Ada",
                "age": 36,
                "score": 9.5,
                "active": true,
                "birthDate": "1815-12-10",
                "note": null,
                "a/b": "escaped"
            },
            "tags": ["math", "engines"]
        }
        JSON;

    public function testNavigatesPointersAndReadsTypedValues(): void
    {
        // GIVEN
        $node = JsonNode::of(Source::json(self::DOCUMENT));

        // WHEN / THEN
        self::assertSame('Ada', $node->get('/customer/name')->string());
        self::assertSame(36, $node->get('/customer/age')->int());
        self::assertSame(9.5, $node->get('/customer/score')->float());
        self::assertTrue($node->get('/customer/active')->bool());
        self::assertSame('engines', $node->get('/tags/1')->string());
        self::assertSame('1815-12-10', $node->get('/customer/birthDate')->dateTime()->format('Y-m-d'));
    }

    public function testWholeNumbersReadAsFloats(): void
    {
        // GIVEN JSON has no int/float distinction for whole numbers
        $node = JsonNode::of(Source::json('{"price": 5}'));

        // WHEN / THEN
        self::assertSame(5.0, $node->get('/price')->float());
    }

    public function testNavigationIsRelativeAndPathsStayAbsolute(): void
    {
        // GIVEN
        $customer = JsonNode::of(Source::json(self::DOCUMENT))->get('/customer');

        // WHEN
        $name = $customer->get('/name');

        // THEN
        self::assertSame('Ada', $name->string());
        self::assertSame('/customer/name', $name->path()->toString());
    }

    public function testEscapedPointerSegmentsResolve(): void
    {
        // GIVEN a member name containing '/' (RFC 6901: ~1)
        $node = JsonNode::of(Source::json(self::DOCUMENT));

        // WHEN / THEN
        self::assertSame('escaped', $node->get('/customer/a~1b')->string());
    }

    public function testExistsDistinguishesMissingFromNull(): void
    {
        // GIVEN
        $node = JsonNode::of(Source::json(self::DOCUMENT));

        // WHEN / THEN
        self::assertTrue($node->exists('/customer/note'));
        self::assertTrue($node->get('/customer/note')->isNull());
        self::assertFalse($node->exists('/customer/missing'));
        self::assertFalse($node->exists('/tags/9'));
    }

    public function testMissingNodeErrorCarriesTheAbsolutePointer(): void
    {
        // GIVEN
        $customer = JsonNode::of(Source::json(self::DOCUMENT))->get('/customer');

        // WHEN
        try {
            $customer->get('/address/city');
            self::fail('Expected MappingFailed.');
        } catch (MappingFailed $exception) {
            // THEN
            $error = $exception->report()->errors[0];
            self::assertSame('tree.missing_node', $error->code);
            self::assertSame('/customer/address/city', $error->pointer->toString());
        }
    }

    public function testTypeErrorsCarryPointerCodeAndActualType(): void
    {
        // GIVEN
        $node = JsonNode::of(Source::json(self::DOCUMENT));

        // WHEN
        try {
            $node->get('/customer/age')->string();
            self::fail('Expected MappingFailed.');
        } catch (MappingFailed $exception) {
            // THEN
            $error = $exception->report()->errors[0];
            self::assertSame('mapping.type', $error->code);
            self::assertSame('Expected string, got int.', $error->message);
            self::assertSame('/customer/age', $error->pointer->toString());
            self::assertSame(36, $error->input);
        }
    }

    public function testEveryTypedAccessorRejectsMismatchedValues(): void
    {
        // GIVEN
        $node = JsonNode::of(Source::json(self::DOCUMENT));

        // WHEN / THEN — each accessor throws with the same code and a precise message
        $cases = [
            ['/customer/name', static fn(JsonNode $n): int => $n->int(), 'Expected int, got string.'],
            ['/customer/active', static fn(JsonNode $n): float => $n->float(), 'Expected float, got bool.'],
            ['/customer/age', static fn(JsonNode $n): bool => $n->bool(), 'Expected bool, got int.'],
            ['/customer/age', static fn(JsonNode $n): \DateTimeImmutable => $n->dateTime(), 'Expected date-time string, got int.'],
        ];

        foreach ($cases as [$pointer, $accessor, $message]) {
            try {
                $accessor($node->get($pointer));
                self::fail(\sprintf('Expected MappingFailed for "%s".', $message));
            } catch (MappingFailed $exception) {
                $error = $exception->report()->errors[0];
                self::assertSame('mapping.type', $error->code);
                self::assertSame($message, $error->message);
            }
        }
    }

    public function testNumericSegmentsMustBeFullyNumeric(): void
    {
        // GIVEN partially-numeric segments must not resolve on arrays
        $node = JsonNode::of(Source::json(self::DOCUMENT));

        // WHEN / THEN
        self::assertFalse($node->exists('/tags/x1'));
        self::assertFalse($node->exists('/tags/1x'));
    }

    public function testResolutionContinuesPastArrayIndexes(): void
    {
        // GIVEN a pointer descending through an array element
        $node = JsonNode::of(Source::json('{"rows": [{"cell": 7}]}'));

        // WHEN / THEN deeper segments resolve, dead ends stay dead
        self::assertSame(7, $node->get('/rows/0/cell')->int());
        self::assertFalse($node->exists('/rows/0/missing'));
    }

    public function testInvalidDateIsAFormatError(): void
    {
        // GIVEN
        $node = JsonNode::of(Source::json('{"when": "not-a-date"}'));

        // WHEN
        try {
            $node->get('/when')->dateTime();
            self::fail('Expected MappingFailed.');
        } catch (MappingFailed $exception) {
            // THEN
            self::assertSame('mapping.format', $exception->report()->errors[0]->code);
        }
    }

    public function testListYieldsIndexedChildNodes(): void
    {
        // GIVEN
        $tags = JsonNode::of(Source::json(self::DOCUMENT))->get('/tags');

        // WHEN
        $items = $tags->list();

        // THEN
        self::assertCount(2, $items);
        self::assertSame('math', $items[0]->string());
        self::assertSame('/tags/1', $items[1]->path()->toString());
    }

    public function testListRejectsObjects(): void
    {
        // GIVEN
        $node = JsonNode::of(Source::json(self::DOCUMENT));

        // THEN
        $this->expectException(MappingFailed::class);

        // WHEN
        $node->get('/customer')->list();
    }

    public function testMapYieldsKeyedChildNodes(): void
    {
        // GIVEN
        $customer = JsonNode::of(Source::json(self::DOCUMENT))->get('/customer');

        // WHEN
        $members = $customer->map();

        // THEN
        self::assertSame('Ada', $members['name']->string());
        self::assertSame('/customer/age', $members['age']->path()->toString());
    }

    public function testMapRejectsArrays(): void
    {
        // GIVEN
        $node = JsonNode::of(Source::json(self::DOCUMENT));

        // THEN
        $this->expectException(MappingFailed::class);

        // WHEN
        $node->get('/tags')->map();
    }

    public function testRawExposesTheDecodedValue(): void
    {
        // GIVEN
        $node = JsonNode::of(Source::json(self::DOCUMENT));

        // WHEN / THEN
        self::assertSame(['math', 'engines'], $node->get('/tags')->raw());
    }

    public function testMalformedJsonBecomesASourceError(): void
    {
        // GIVEN
        $source = Source::json('{broken');

        // WHEN
        try {
            JsonNode::of($source);
            self::fail('Expected MappingFailed.');
        } catch (MappingFailed $exception) {
            // THEN
            self::assertSame('source.malformed_json', $exception->report()->errors[0]->code);
        }
    }

    public function testNumericStringObjectKeysAreNotConfusedWithArrayIndexes(): void
    {
        // GIVEN an object with a numeric member name
        $node = JsonNode::of(Source::json('{"items": {"0": "zero"}}'));

        // WHEN / THEN
        self::assertSame('zero', $node->get('/items/0')->string());
    }
}
