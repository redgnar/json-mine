<?php

declare(strict_types=1);

namespace JsonMine\Tests\Error;

use JsonMine\Error\ErrorReport;
use JsonMine\Error\MappingError;
use JsonMine\JsonPointer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ErrorReport::class)]
final class ErrorReportTest extends TestCase
{
    public function testEmptyReportMeansValid(): void
    {
        // GIVEN
        $report = ErrorReport::none();

        // THEN
        self::assertTrue($report->isEmpty());
        self::assertCount(0, $report);
        self::assertSame([], $report->errors);
    }

    public function testCollectsErrorsInOrder(): void
    {
        // GIVEN
        $first = $this->error('/nodes/0', 'mapping.type');
        $second = $this->error('/nodes/1', 'schema.required');

        // WHEN
        $report = ErrorReport::of($first, $second);

        // THEN
        self::assertFalse($report->isEmpty());
        self::assertCount(2, $report);
        self::assertSame([$first, $second], $report->errors);
    }

    public function testMergePreservesAllErrorsAndOrderAndLeavesOperandsIntact(): void
    {
        // GIVEN two multi-error reports (merge must not drop any element from either side)
        $first = $this->error('/id', 'schema.required');
        $second = $this->error('/nodes/2', 'mapping.type');
        $third = $this->error('/nodes/5', 'mapping.unknown_variant');
        $fourth = $this->error('/title', 'form.rule');
        $left = ErrorReport::of($first, $second);
        $right = ErrorReport::of($third, $fourth);

        // WHEN
        $merged = $left->merge($right);

        // THEN
        self::assertSame([$first, $second, $third, $fourth], $merged->errors);
        self::assertCount(2, $left);
        self::assertCount(2, $right);
    }

    public function testOfNormalizesNamedArgumentsToAList(): void
    {
        // GIVEN a variadic call receiving string keys (spread of an associative array)
        $error = $this->error('/id', 'schema.required');

        // WHEN
        $report = ErrorReport::of(...['stringKey' => $error]);

        // THEN the list<MappingError> invariant holds
        self::assertSame([$error], $report->errors);
    }

    public function testIsIterable(): void
    {
        // GIVEN
        $error = $this->error('/title', 'mapping.type');
        $report = ErrorReport::of($error);

        // WHEN
        $collected = iterator_to_array($report);

        // THEN
        self::assertSame([$error], $collected);
    }

    private function error(string $pointer, string $code): MappingError
    {
        return new MappingError(JsonPointer::fromString($pointer), $code, 'Test error.');
    }
}
