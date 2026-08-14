<?php

declare(strict_types=1);

namespace Ingot\Examples\Workflow\Definition;

use Ingot\Attribute\Constraints;

/**
 * Pauses the flow for a number of seconds — at least one, at most a day.
 */
final readonly class DelayNode extends Node
{
    public function __construct(
        string $id,
        #[Constraints(minimum: 1, maximum: 86400)]
        public int $seconds,
        string $name = '',
    ) {
        parent::__construct($id, $name);
    }
}
