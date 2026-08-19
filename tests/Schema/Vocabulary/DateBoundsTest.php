<?php

declare(strict_types=1);

namespace Ingot\Tests\Schema\Vocabulary;

use Ingot\Schema\OpisSchemaValidator;
use Ingot\Schema\Schema;
use Opis\JsonSchema\Exceptions\ParseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A date range, which standard JSON Schema cannot express: `formatMinimum` and
 * `formatMaximum` beside `"format": "date"`, with the meaning ajv-formats gives
 * them — so one document is enforced the same way here and in a browser.
 */
final class DateBoundsTest extends TestCase
{
    private const string SCHEMA = '{
        "type": "object",
        "properties": {
            "when": {
                "type": "string",
                "format": "date",
                "formatMinimum": "2026-01-01",
                "formatMaximum": "2026-12-31"
            }
        }
    }';

    /**
     * @return \Generator<string, array{string, string|null}>
     */
    public static function dates(): \Generator
    {
        yield 'a date inside the range' => ['2026-06-15', null];
        yield 'the first day allowed' => ['2026-01-01', null];
        yield 'the last day allowed' => ['2026-12-31', null];

        yield 'the day before the range' => ['2025-12-31', 'schema.formatMinimum'];
        yield 'the day after the range' => ['2027-01-01', 'schema.formatMaximum'];
        yield 'a year too early' => ['2025-06-15', 'schema.formatMinimum'];

        // A value that is not a date is `format`'s complaint, and only its own:
        // being out of range as well would be two complaints about one mistake.
        yield 'not a date at all' => ['tomorrow', 'schema.format'];
        yield 'a day that does not exist' => ['2026-02-30', 'schema.format'];
        yield 'the right day in the wrong shape' => ['2026-6-15', 'schema.format'];
        yield 'a timestamp where a date belongs' => ['2026-06-15T10:00:00Z', 'schema.format'];
    }

    #[DataProvider('dates')]
    public function testTheRangeIsEnforced(string $date, ?string $code): void
    {
        // GIVEN a schema bounding a date on both sides
        $validator = new OpisSchemaValidator();

        // WHEN
        $report = $validator->validate(self::document($date), Schema::fromJson(self::SCHEMA));

        // THEN
        if ($code === null) {
            self::assertTrue($report->isEmpty(), \sprintf('Expected "%s" to be accepted.', $date));

            return;
        }

        self::assertFalse($report->isEmpty(), \sprintf('Expected "%s" to be refused.', $date));
        self::assertSame($code, $report->errors[0]->code);
        self::assertSame('/when', $report->errors[0]->pointer->toString());
    }

    public function testTheRefusalSaysWhichEndWasMissedAndWhere(): void
    {
        // GIVEN a schema bounding a date on both sides
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(self::SCHEMA);

        // WHEN a date falls off either end
        $tooEarly = $validator->validate(self::document('2025-12-31'), $schema);
        $tooLate = $validator->validate(self::document('2027-01-01'), $schema);

        // THEN each message names the bound that was missed, so a client can
        // repeat it to whoever is filling the form in
        self::assertStringContainsString('earlier', $tooEarly->errors[0]->message);
        self::assertStringContainsString('2026-01-01', $tooEarly->errors[0]->message);
        self::assertStringContainsString('later', $tooLate->errors[0]->message);
        self::assertStringContainsString('2026-12-31', $tooLate->errors[0]->message);
    }

    public function testOnlyOneEndNeedsToBeGiven(): void
    {
        // GIVEN a range open at the top
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "object", "properties": {"when": {"type": "string", "format": "date", "formatMinimum": "2026-01-01"}}}');

        // WHEN / THEN nothing is too late, and yesterday is still too early
        self::assertTrue($validator->validate(self::document('2999-01-01'), $schema)->isEmpty());
        self::assertFalse($validator->validate(self::document('2025-12-31'), $schema)->isEmpty());
    }

    public function testAValueOfAnotherTypeIsLeftToTheKeywordsThatJudgeTypes(): void
    {
        // GIVEN a bounded date and a number where the date belongs
        $validator = new OpisSchemaValidator();

        // WHEN
        $report = $validator->validate(json_decode('{"when": 2026}', false, flags: \JSON_THROW_ON_ERROR), Schema::fromJson(self::SCHEMA));

        // THEN the complaint is about the type, once
        self::assertSame(['schema.type'], array_map(static fn($error): string => $error->code, $report->errors));
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function boundsThatAreNotDates(): \Generator
    {
        yield 'a word' => ['yesterday'];
        yield 'a date with a time after it' => ['2026-01-01T10:00:00Z'];
        yield 'a date with a word before it' => ['about 2026-01-01'];
        yield 'a day that does not exist' => ['2026-02-30'];
        yield 'a month that does not exist' => ['2026-13-01'];
        yield 'a date missing its zeroes' => ['2026-1-1'];
        yield 'nothing at all' => [''];
    }

    #[DataProvider('boundsThatAreNotDates')]
    public function testABoundThatIsNotAWholeCalendarDateIsRefused(string $bound): void
    {
        // GIVEN a schema whose bound no date could ever be compared against
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson(\sprintf('{"type": "string", "format": "date", "formatMinimum": "%s"}', $bound));

        // WHEN / THEN the schema itself is the mistake, and it is reported as one
        $this->expectException(ParseException::class);

        $validator->validate('2026-06-15', $schema);
    }

    public function testABoundBesideAnotherFormatIsRefused(): void
    {
        // GIVEN a bound where string order is not time order
        $validator = new OpisSchemaValidator();
        $schema = Schema::fromJson('{"type": "string", "format": "date-time", "formatMinimum": "2026-01-01"}');

        // WHEN / THEN
        $this->expectException(ParseException::class);

        $validator->validate('2026-01-01T10:00:00Z', $schema);
    }

    private static function document(string $date): object
    {
        $document = json_decode(json_encode(['when' => $date], \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $document);

        return $document;
    }
}
