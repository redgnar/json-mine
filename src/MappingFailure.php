<?php

declare(strict_types=1);

namespace JsonMine;

use JsonMine\Error\ErrorReport;
use JsonMine\Error\MappingFailed;

/**
 * @implements MappingResult<never>
 */
final readonly class MappingFailure implements MappingResult
{
    /**
     * @throws \InvalidArgumentException when the report is empty — a failure must explain itself
     */
    public function __construct(
        private ErrorReport $report,
    ) {
        if ($report->isEmpty()) {
            throw new \InvalidArgumentException('A mapping failure requires at least one error.');
        }
    }

    public function isSuccess(): bool
    {
        return false;
    }

    public function value(): never
    {
        throw new MappingFailed($this->report);
    }

    public function errors(): ErrorReport
    {
        return $this->report;
    }
}
