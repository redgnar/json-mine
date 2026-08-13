<?php

declare(strict_types=1);

namespace Ingot\Examples\Workflow\Definition;

/**
 * A directed connection between two nodes, referencing them by id.
 * JSON Schema cannot check these references — {@see GraphIntegrityValidator} does.
 */
final readonly class Edge
{
    public function __construct(
        public string $from,
        public string $to,
    ) {}
}
