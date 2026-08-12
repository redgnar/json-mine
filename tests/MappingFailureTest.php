<?php

declare(strict_types=1);

namespace Ingot\Tests;

use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\Error\MappingFailed;
use Ingot\JsonPointer;
use Ingot\MappingFailure;
use Ingot\MappingResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MappingFailure::class)]
final class MappingFailureTest extends TestCase
{
    public function testExposesTheReport(): void
    {
        // GIVEN
        $report = $this->report();

        // WHEN
        $result = $this->failure($report);

        // THEN
        self::assertFalse($result->isSuccess());
        self::assertSame($report, $result->errors());
    }

    public function testValueThrowsMappingFailedCarryingTheReport(): void
    {
        // GIVEN
        $report = $this->report();
        $result = $this->failure($report);

        // WHEN
        try {
            $result->value();
        } catch (MappingFailed $exception) {
            // THEN
            self::assertSame($report, $exception->report());
        }
        // (no exception → zero assertions → the test fails as risky)
    }

    public function testRejectsAnEmptyReport(): void
    {
        // GIVEN
        $report = ErrorReport::none();

        // THEN a failure must explain itself
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one error');

        // WHEN
        new MappingFailure($report);
    }

    /**
     * Typed as the interface — the way callers of tryMap() see the result.
     *
     * @return MappingResult<never>
     */
    private function failure(ErrorReport $report): MappingResult
    {
        return new MappingFailure($report);
    }

    private function report(): ErrorReport
    {
        return ErrorReport::of(
            new MappingError(JsonPointer::fromString('/id'), 'schema.required', 'Missing required property.'),
        );
    }
}
