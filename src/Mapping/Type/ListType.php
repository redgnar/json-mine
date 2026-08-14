<?php

declare(strict_types=1);

namespace Ingot\Mapping\Type;

/**
 * A JSON array mapped to a PHP list (sequential integer keys).
 */
final readonly class ListType implements TypeNode
{
    /**
     * @param ?ConstraintSet $constraints set from #[Constraints] — the
     *        hydrated list must satisfy its array keywords
     */
    public function __construct(
        public TypeNode $item,
        public ?ConstraintSet $constraints = null,
    ) {}
}
