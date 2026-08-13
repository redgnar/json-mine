<?php

declare(strict_types=1);

namespace Ingot\Examples\Workflow\Definition;

use Ingot\Attribute\Format;

/**
 * Calls an HTTP endpoint. The URL is format-validated at mapping time —
 * a malformed value is a data error long before anything executes.
 */
final readonly class HttpNode extends Node
{
    public function __construct(
        string $id,
        #[Format('uri')]
        public string $url,
        public string $method = 'GET',
        string $name = '',
    ) {
        parent::__construct($id, $name);
    }
}
