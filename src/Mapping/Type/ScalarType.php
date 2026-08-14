<?php

declare(strict_types=1);

namespace Ingot\Mapping\Type;

final readonly class ScalarType implements TypeNode
{
    /**
     * @param ?FormatKind $format string members only — set from #[Format],
     *        the hydrated string must match it
     * @param ?ConstraintSet $constraints string and numeric members — set
     *        from #[Constraints], the hydrated value must satisfy it
     */
    public function __construct(
        public ScalarKind $kind,
        public ?FormatKind $format = null,
        public ?ConstraintSet $constraints = null,
    ) {}
}
