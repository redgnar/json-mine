<?php

declare(strict_types=1);

namespace Ingot\Mapping\Type;

final readonly class ScalarType implements TypeNode
{
    /**
     * @param ?FormatKind $format string members only — set from #[Format],
     *        the hydrated string must match it
     */
    public function __construct(
        public ScalarKind $kind,
        public ?FormatKind $format = null,
    ) {}
}
