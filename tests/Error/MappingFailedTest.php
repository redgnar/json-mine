<?php

declare(strict_types=1);

namespace JsonMine\Tests\Error;

use JsonMine\Error\ErrorReport;
use JsonMine\Error\MappingError;
use JsonMine\Error\MappingFailed;
use JsonMine\JsonPointer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MappingFailed::class)]
final class MappingFailedTest extends TestCase
{
    public function testCarriesTheFullReport(): void
    {
        // GIVEN
        $report = ErrorReport::of(
            new MappingError(JsonPointer::fromString('/nodes/3/type'), 'mapping.unknown_variant', 'Unknown variant "webhook".'),
            new MappingError(JsonPointer::fromString('/id'), 'schema.required', 'Missing required property.'),
        );

        // WHEN
        $exception = new MappingFailed($report);

        // THEN
        self::assertSame($report, $exception->report());
    }

    public function testMessageSummarizesCountAndFirstError(): void
    {
        // GIVEN
        $report = ErrorReport::of(
            new MappingError(JsonPointer::fromString('/nodes/3/type'), 'mapping.unknown_variant', 'Unknown variant "webhook".'),
            new MappingError(JsonPointer::fromString('/id'), 'schema.required', 'Missing required property.'),
        );

        // WHEN
        $exception = new MappingFailed($report);

        // THEN
        self::assertSame(
            'Mapping failed with 2 error(s). First: [mapping.unknown_variant] Unknown variant "webhook". at "/nodes/3/type".',
            $exception->getMessage(),
        );
    }

    public function testHasGenericMessageForEmptyReport(): void
    {
        // GIVEN
        $report = ErrorReport::none();

        // WHEN
        $exception = new MappingFailed($report);

        // THEN
        self::assertSame('Mapping failed.', $exception->getMessage());
    }
}
