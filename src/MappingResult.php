<?php

declare(strict_types=1);

namespace JsonMine;

use JsonMine\Error\ErrorReport;
use JsonMine\Error\MappingFailed;

/**
 * Outcome of the non-throwing mapping API ({@see TreeMapper::tryMap()}).
 *
 * Either {@see MappingSuccess} carrying the mapped value, or
 * {@see MappingFailure} carrying the aggregated error report.
 *
 * @template-covariant T
 */
interface MappingResult
{
    /**
     * @phpstan-assert-if-true MappingSuccess<T> $this
     * @phpstan-assert-if-false MappingFailure $this
     */
    public function isSuccess(): bool;

    /**
     * The mapped value.
     *
     * @return T
     *
     * @throws MappingFailed when the mapping failed
     */
    public function value(): mixed;

    /**
     * Empty on success.
     */
    public function errors(): ErrorReport;
}
