<?php

declare(strict_types=1);

namespace Ingot\Examples\Forms\Definition;

use Ingot\Attribute\Extras;

/**
 * Fallback for field types this application does not know (plugin fields):
 * the raw payload survives in $extras, so an editor can load → edit → save
 * a definition without understanding every field type in it.
 */
final readonly class GenericField extends Field
{
    /**
     * @param array<string, mixed> $extras
     */
    public function __construct(
        public string $type,
        string $name = '',
        string $label = '',
        bool $required = false,
        #[Extras]
        public array $extras = [],
    ) {
        parent::__construct($name, $label, $required);
    }
}
