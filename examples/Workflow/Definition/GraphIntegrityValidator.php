<?php

declare(strict_types=1);

namespace Ingot\Examples\Workflow\Definition;

use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * Referential integrity — the rules JSON Schema cannot express: node ids are
 * unique, and every edge endpoint references an existing node. Runs on the
 * fully hydrated document; errors land in the same report as schema and
 * mapping errors.
 *
 * @implements ObjectValidator<Workflow>
 */
final class GraphIntegrityValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        $ids = [];

        foreach ($object->nodes as $index => $node) {
            if (isset($ids[$node->id])) {
                $context->addError(
                    \sprintf('/nodes/%d/id', $index),
                    'workflow.node.duplicate-id',
                    \sprintf('Node id "%s" is not unique.', $node->id),
                    $node->id,
                );
            }

            $ids[$node->id] = true;
        }

        foreach ($object->edges as $index => $edge) {
            foreach (['from' => $edge->from, 'to' => $edge->to] as $end => $reference) {
                if (!isset($ids[$reference])) {
                    $context->addError(
                        \sprintf('/edges/%d/%s', $index, $end),
                        'workflow.edge.dangling',
                        \sprintf('Edge references unknown node "%s".', $reference),
                        $reference,
                    );
                }
            }
        }
    }
}
