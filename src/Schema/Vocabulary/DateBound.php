<?php

declare(strict_types=1);

namespace Ingot\Schema\Vocabulary;

/**
 * What both ends of a date range agree a date is: `YYYY-MM-DD`, whole, and a
 * day that exists — 2026-02-30 is neither a bound nor a value.
 *
 * Reformatting what was parsed and comparing it to the input is what makes all
 * three of those one check: a rolled-over day, a missing zero and anything
 * tacked onto the end all come back different from what came in.
 */
final class DateBound
{
    public static function isCalendarDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
