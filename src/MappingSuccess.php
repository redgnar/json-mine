<?php

declare(strict_types=1);

namespace JsonMine;

use JsonMine\Error\ErrorReport;

/**
 * @template-covariant T
 *
 * @implements MappingResult<T>
 */
final readonly class MappingSuccess implements MappingResult
{
    public function __construct(
        /** @var T */
        private mixed $value,
    ) {}

    public function isSuccess(): bool
    {
        return true;
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function errors(): ErrorReport
    {
        return ErrorReport::none();
    }
}
