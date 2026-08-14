<?php

declare(strict_types=1);

namespace Ingot\Examples\Forms\Definition;

use Ingot\Attribute\Constraints;

final readonly class TextField extends Field
{
    public function __construct(
        string $name,
        string $label = '',
        bool $required = false,
        // A zero or negative length limit could never be satisfied by any
        // submission — reject it in the definition, not at submission time.
        #[Constraints(exclusiveMinimum: 0)]
        public ?int $maxLength = null,
        #[Constraints(minLength: 1)]
        public ?string $pattern = null,
    ) {
        parent::__construct($name, $label, $required);
    }
}
