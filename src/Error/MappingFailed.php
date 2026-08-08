<?php

declare(strict_types=1);

namespace JsonMine\Error;

/**
 * Thrown by the throwing mapping API when a document cannot be mapped.
 *
 * Carries the full aggregated report; the exception message summarizes it.
 */
final class MappingFailed extends \RuntimeException
{
    public function __construct(
        private readonly ErrorReport $report,
    ) {
        parent::__construct($this->summarize($report));
    }

    public function report(): ErrorReport
    {
        return $this->report;
    }

    private function summarize(ErrorReport $report): string
    {
        if ($report->isEmpty()) {
            return 'Mapping failed.';
        }

        $first = $report->errors[0];

        return \sprintf(
            'Mapping failed with %d error(s). First: [%s] %s at "%s".',
            $report->count(),
            $first->code,
            $first->message,
            $first->pointer->toString(),
        );
    }
}
