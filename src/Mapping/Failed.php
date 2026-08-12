<?php

declare(strict_types=1);

namespace Ingot\Mapping;

/**
 * Internal sentinel: a subtree could not be hydrated. The errors explaining
 * why are already in the collector — the sentinel lets sibling subtrees keep
 * mapping so all problems are reported at once.
 *
 * @internal
 */
enum Failed
{
    case Value;
}
