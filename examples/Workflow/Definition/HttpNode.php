<?php

declare(strict_types=1);

namespace Ingot\Examples\Workflow\Definition;

use Ingot\Attribute\Constraints;
use Ingot\Attribute\Format;

/**
 * Calls an HTTP endpoint. The URL is format-validated and the payload is
 * constraint-validated at mapping time — a malformed value is a data error
 * long before anything executes.
 */
final readonly class HttpNode extends Node
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $id,
        #[Format('uri')]
        public string $url,
        #[Constraints(pattern: '^(GET|POST|PUT|PATCH|DELETE)$')]
        public string $method = 'GET',
        // Timeouts come in half-second steps, above zero and below the
        // 5-minute gateway limit.
        #[Constraints(exclusiveMinimum: 0, exclusiveMaximum: 300, multipleOf: 0.5)]
        public float $timeoutSeconds = 30.0,
        // When headers are given at all, an empty bag is a mistake.
        #[Constraints(minProperties: 1, maxProperties: 20)]
        public array $headers = [],
        string $name = '',
    ) {
        parent::__construct($id, $name);
    }
}
