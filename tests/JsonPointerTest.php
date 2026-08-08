<?php

declare(strict_types=1);

namespace JsonMine\Tests;

use JsonMine\JsonPointer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonPointer::class)]
final class JsonPointerTest extends TestCase
{
    public function testRootPointerIsEmptyString(): void
    {
        // GIVEN
        $pointer = JsonPointer::root();

        // WHEN
        $string = $pointer->toString();

        // THEN
        self::assertSame('', $string);
        self::assertTrue($pointer->isRoot());
    }

    public function testParsesPointerIntoDecodedSegments(): void
    {
        // GIVEN
        $raw = '/nodes/3/type';

        // WHEN
        $pointer = JsonPointer::fromString($raw);

        // THEN
        self::assertSame(['nodes', '3', 'type'], $pointer->segments);
        self::assertFalse($pointer->isRoot());
    }

    public function testDecodesEscapedCharactersInRfc6901Order(): void
    {
        // GIVEN a segment containing '/' (escaped as ~1) and '~' (escaped as ~0)
        $raw = '/a~1b/m~0n';

        // WHEN
        $pointer = JsonPointer::fromString($raw);

        // THEN
        self::assertSame(['a/b', 'm~n'], $pointer->segments);
    }

    public function testEncodesSpecialCharactersWhenConvertedBackToString(): void
    {
        // GIVEN
        $pointer = JsonPointer::root()->append('a/b')->append('m~n');

        // WHEN
        $string = $pointer->toString();

        // THEN
        self::assertSame('/a~1b/m~0n', $string);
    }

    public function testAppendReturnsNewInstanceAndKeepsOriginalIntact(): void
    {
        // GIVEN
        $base = JsonPointer::fromString('/nodes');

        // WHEN
        $extended = $base->append(3)->append('type');

        // THEN
        self::assertSame('/nodes', $base->toString());
        self::assertSame('/nodes/3/type', $extended->toString());
    }

    public function testAppendNormalizesIntegerSegmentToString(): void
    {
        // GIVEN
        $pointer = JsonPointer::root();

        // WHEN
        $extended = $pointer->append(3);

        // THEN list<string> invariant holds regardless of the input segment type
        self::assertSame(['3'], $extended->segments);
    }

    public function testJoinResolvesOtherPointerAgainstBase(): void
    {
        // GIVEN
        $base = JsonPointer::fromString('/nodes/3');
        $relative = JsonPointer::fromString('/connections/0');

        // WHEN
        $joined = $base->join($relative);

        // THEN
        self::assertSame('/nodes/3/connections/0', $joined->toString());
        self::assertSame('/nodes/3', $base->toString());
    }

    public function testJoinWithRootPointerIsIdentity(): void
    {
        // GIVEN
        $base = JsonPointer::fromString('/fields/1');

        // WHEN
        $joined = $base->join(JsonPointer::root());

        // THEN
        self::assertSame(['fields', '1'], $joined->segments);
    }

    public function testRoundTripsThroughStringRepresentation(): void
    {
        // GIVEN
        $raw = '/fields/0/options/2';

        // WHEN
        $roundTripped = JsonPointer::fromString(JsonPointer::fromString($raw)->toString());

        // THEN
        self::assertSame($raw, (string) $roundTripped);
    }

    public function testRejectsNonEmptyPointerWithoutLeadingSlash(): void
    {
        // GIVEN
        $raw = 'nodes/3';

        // THEN
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must start with "/"');

        // WHEN
        JsonPointer::fromString($raw);
    }
}
