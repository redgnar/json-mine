<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

use JsonMine\Attribute\Extras;

/**
 * Fallback for unknown field variants — preserves the raw payload.
 * A fallback must be a subtype of the union base (see VariantRegistry).
 */
final readonly class GenericField extends Field
{
    /**
     * @param array<string, mixed> $extras
     */
    public function __construct(
        public string $type,
        string $name = '',
        #[Extras]
        public array $extras = [],
    ) {
        parent::__construct($name);
    }
}
