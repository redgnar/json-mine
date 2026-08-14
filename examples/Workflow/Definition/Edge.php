<?php

declare(strict_types=1);

namespace Ingot\Examples\Workflow\Definition;

use Ingot\Attribute\Constraints;

/**
 * A directed connection between two nodes, referencing them by id. The ids
 * must be non-empty — but JSON Schema (and these constraints) cannot check
 * that the references resolve; {@see GraphIntegrityValidator} does.
 */
final readonly class Edge
{
    public function __construct(
        #[Constraints(minLength: 1)]
        public string $from,
        #[Constraints(minLength: 1)]
        public string $to,
    ) {}
}
