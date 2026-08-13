<?php

declare(strict_types=1);

namespace Ingot\Mapping\Type;

/**
 * Target is \DateTimeImmutable, produced from a date-time string.
 */
final readonly class DateTimeType implements TypeNode
{
    /**
     * @param ?FormatKind $format set from #[Format]: DateTime restricts the
     *        accepted syntax to RFC 3339 (PHP's lenient parsing otherwise),
     *        Date accepts full-date strings only
     */
    public function __construct(
        public ?FormatKind $format = null,
    ) {}
}
