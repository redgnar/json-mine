<?php

declare(strict_types=1);

namespace JsonMine\Tree;

/**
 * Internal sentinel: a pointer did not resolve. Distinguishes "no value" from
 * a legitimate JSON null.
 *
 * @internal
 */
enum Missing
{
    case Value;
}
