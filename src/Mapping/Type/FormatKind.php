<?php

declare(strict_types=1);

namespace Ingot\Mapping\Type;

/**
 * The formats the engine understands in #[Format] — a closed set on purpose:
 * a format the engine cannot validate would silently validate nothing.
 *
 * Backed values are the wire names, matching JSON Schema `format` vocabulary.
 */
enum FormatKind: string
{
    case DateTime = 'date-time';
    case Date = 'date';
    case Uuid = 'uuid';
    case Uri = 'uri';
    case Email = 'email';

    public function matches(string $value): bool
    {
        return match ($this) {
            self::DateTime => $this->isDateTime($value),
            self::Date => $this->isDate($value),
            self::Uuid => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1,
            // RFC 3986 in outline: a scheme, a colon, no whitespace. Full URI
            // grammar is deliberately out of scope for a mapping hint.
            self::Uri => preg_match('/^[a-z][a-z0-9+.\-]*:\S*$/i', $value) === 1,
            self::Email => filter_var($value, \FILTER_VALIDATE_EMAIL) !== false,
        };
    }

    /**
     * RFC 3339 date-time: full date, 'T', full time (ranges enforced by the
     * pattern), 'Z' or a numeric offset. PHP's lenient parser would also
     * accept "tomorrow" — this will not. checkdate() adds what a regex cannot
     * know: month lengths and leap years.
     */
    private function isDateTime(string $value): bool
    {
        $pattern = '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})[Tt](?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:[Zz]|[+-]\d{2}:\d{2})$/';

        return preg_match($pattern, $value, $parts) === 1
            && checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year']);
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})$/', $value, $parts) === 1
            && checkdate((int) $parts['month'], (int) $parts['day'], (int) $parts['year']);
    }
}
