<?php

declare(strict_types=1);

namespace Ingot;

/**
 * Type coercion mode for mapping.
 *
 * Strict is the library default: JSON types must match the target types exactly.
 * Lax enables the documented coercion table (e.g. "123" → 123, ISO-8601 string → DateTimeImmutable).
 */
enum Coercion
{
    case Strict;
    case Lax;
}
