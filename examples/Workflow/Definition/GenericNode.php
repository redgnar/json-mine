<?php

declare(strict_types=1);

namespace Ingot\Examples\Workflow\Definition;

use Ingot\Attribute\Extras;

/**
 * Fallback for node types this application does not know: the discriminator
 * value and the raw payload survive, so an editor can load → edit → save a
 * workflow containing plugin nodes it cannot execute. An execution engine
 * would build the processor in strict mode instead and fail on these.
 */
final readonly class GenericNode extends Node
{
    /**
     * @param array<string, mixed> $extras
     */
    public function __construct(
        public string $type,
        string $id = '',
        string $name = '',
        #[Extras]
        public array $extras = [],
    ) {
        parent::__construct($id, $name);
    }
}
