<?php

declare(strict_types=1);

namespace Ingot\Tree;

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
