<?php

declare(strict_types=1);

namespace Ingot\Examples\Workflow;

use Ingot\Examples\Workflow\Definition\DelayNode;
use Ingot\Examples\Workflow\Definition\GenericNode;
use Ingot\Examples\Workflow\Definition\GraphIntegrityValidator;
use Ingot\Examples\Workflow\Definition\HttpNode;
use Ingot\Examples\Workflow\Definition\Node;
use Ingot\Examples\Workflow\Definition\Workflow;
use Ingot\MapperBuilder;
use Ingot\Schema\Schema;
use Ingot\Source;
use Ingot\TreeMapper;

/**
 * The workflow pipeline wired together:
 *
 *   workflow.json ─(meta-schema)→ Workflow ─(graph rules)→ typed node graph
 *
 * Node types come from a runtime registry (plugin territory), with two
 * tolerance modes for unknown types:
 *
 * - lenient (editors, migrations): unknown nodes hydrate into GenericNode,
 *   payload preserved, and the definition round-trips losslessly;
 * - strict (execution engines): an unknown node type is a data error.
 */
final class WorkflowProcessor
{
    private readonly TreeMapper $mapper;

    public function __construct(bool $lenient = true)
    {
        $builder = MapperBuilder::create()
            ->withSchema(Workflow::class, Schema::fromFile(__DIR__ . '/workflow.schema.json'))
            ->withValidator(Workflow::class, new GraphIntegrityValidator())
            // Plugins register their node types at bootstrap:
            ->withVariant(Node::class, 'http', HttpNode::class)
            ->withVariant(Node::class, 'delay', DelayNode::class);

        if ($lenient) {
            $builder = $builder->withVariantFallback(Node::class, GenericNode::class);
        }

        $this->mapper = $builder->build();
    }

    /**
     * @throws \Ingot\Error\MappingFailed with the aggregated report (meta-schema,
     *         type mapping, and graph rules) when the definition is invalid
     */
    public function load(Source $source): Workflow
    {
        return $this->mapper->map(Workflow::class, $source);
    }

    /**
     * The definition back as JSON — lossless even for unknown node types
     * and vendor extensions.
     */
    public function save(Workflow $workflow): string
    {
        return json_encode(
            $this->mapper->normalize($workflow),
            \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
        );
    }
}
