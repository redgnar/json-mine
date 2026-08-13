<?php

declare(strict_types=1);

namespace Ingot\Examples\Workflow\Definition;

/**
 * Pauses the flow for a number of seconds.
 */
final readonly class DelayNode extends Node
{
    public function __construct(
        string $id,
        public int $seconds,
        string $name = '',
    ) {
        parent::__construct($id, $name);
    }
}
