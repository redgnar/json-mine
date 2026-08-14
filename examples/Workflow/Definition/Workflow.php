<?php

declare(strict_types=1);

namespace Ingot\Examples\Workflow\Definition;

use Ingot\Attribute\Constraints;
use Ingot\Attribute\Extras;

/**
 * A workflow definition: a graph of typed nodes connected by edges.
 * Vendor extensions (x-*) land in $extras and survive the round-trip.
 */
final readonly class Workflow
{
    /**
     * @param list<Node> $nodes
     * @param list<Edge> $edges
     * @param array<string, mixed> $extras
     */
    public function __construct(
        #[Constraints(minLength: 1, pattern: '^[a-z][a-z0-9-]*$')]
        public string $id,
        // Mirrors the hand-written meta-schema's `"minLength": 1` — the
        // engine enforces it even when no schema pre-check runs.
        #[Constraints(minLength: 1)]
        public string $name,
        public array $nodes = [],
        public array $edges = [],
        #[Extras]
        public array $extras = [],
    ) {}
}
