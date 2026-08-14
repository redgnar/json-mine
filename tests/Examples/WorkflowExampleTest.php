<?php

declare(strict_types=1);

namespace Ingot\Tests\Examples;

use Ingot\Error\MappingFailed;
use Ingot\Examples\Workflow\Definition\DelayNode;
use Ingot\Examples\Workflow\Definition\GenericNode;
use Ingot\Examples\Workflow\Definition\HttpNode;
use Ingot\Examples\Workflow\Definition\Node;
use Ingot\Examples\Workflow\Definition\Workflow;
use Ingot\Examples\Workflow\WorkflowProcessor;
use Ingot\Mapping\VariantRegistry;
use Ingot\SchemaGen\SchemaGenerator;
use Ingot\Source;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end run of the examples/Workflow pipeline — living documentation:
 * open discriminated union (plugin registry + fallback), referential graph
 * rules on the hydrated document, format-validated node payloads, and a
 * lossless round-trip.
 */
final class WorkflowExampleTest extends TestCase
{
    private const string EXAMPLE_WORKFLOW = __DIR__ . '/../../examples/Workflow/example-workflow.json';

    public function testLoadsTheExampleWorkflowIncludingAnUnknownNodeType(): void
    {
        // GIVEN
        $processor = new WorkflowProcessor();

        // WHEN
        $workflow = $processor->load(Source::file(self::EXAMPLE_WORKFLOW));

        // THEN registered types hydrate into their classes
        self::assertSame('order-flow', $workflow->id);
        self::assertInstanceOf(HttpNode::class, $workflow->nodes[0]);
        self::assertSame('https://api.example.com/orders', $workflow->nodes[0]->url);
        self::assertInstanceOf(DelayNode::class, $workflow->nodes[1]);
        self::assertSame(30, $workflow->nodes[1]->seconds);
        // the unknown "webhook" type fell back to GenericNode, payload preserved
        self::assertInstanceOf(GenericNode::class, $workflow->nodes[2]);
        self::assertSame('webhook', $workflow->nodes[2]->type);
        self::assertSame('order.created', $workflow->nodes[2]->extras['on']);
    }

    public function testStrictModeRejectsAnUnknownNodeType(): void
    {
        // GIVEN an execution engine builds the processor without the fallback
        $processor = new WorkflowProcessor(lenient: false);

        // WHEN
        try {
            $processor->load(Source::file(self::EXAMPLE_WORKFLOW));
            self::fail('Expected MappingFailed.');
        } catch (MappingFailed $exception) {
            // THEN the unknown node is a data error at its exact location
            $error = $exception->report()->errors[0];
            self::assertSame('mapping.unknown_variant', $error->code);
            self::assertSame('/nodes/2/type', $error->pointer->toString());
            self::assertStringContainsString('"webhook"', $error->message);
        }
    }

    public function testDanglingEdgeReferenceIsRejected(): void
    {
        // GIVEN an edge pointing at a node that does not exist
        $processor = new WorkflowProcessor();
        $json = <<<'JSON'
            {
                "id": "wf",
                "name": "Dangling",
                "nodes": [
                    {"type": "delay", "id": "a", "seconds": 1}
                ],
                "edges": [
                    {"from": "a", "to": "missing"}
                ]
            }
            JSON;

        // WHEN
        try {
            $processor->load(Source::json($json));
            self::fail('Expected MappingFailed.');
        } catch (MappingFailed $exception) {
            // THEN
            $error = $exception->report()->errors[0];
            self::assertSame('workflow.edge.dangling', $error->code);
            self::assertSame('/edges/0/to', $error->pointer->toString());
            self::assertSame('missing', $error->input);
        }
    }

    public function testDuplicateNodeIdIsRejected(): void
    {
        // GIVEN two nodes claiming the same id
        $processor = new WorkflowProcessor();
        $json = <<<'JSON'
            {
                "id": "wf",
                "name": "Duplicates",
                "nodes": [
                    {"type": "delay", "id": "a", "seconds": 1},
                    {"type": "delay", "id": "a", "seconds": 2}
                ]
            }
            JSON;

        // WHEN
        try {
            $processor->load(Source::json($json));
            self::fail('Expected MappingFailed.');
        } catch (MappingFailed $exception) {
            // THEN
            $error = $exception->report()->errors[0];
            self::assertSame('workflow.node.duplicate-id', $error->code);
            self::assertSame('/nodes/1/id', $error->pointer->toString());
        }
    }

    public function testMalformedNodeUrlIsAFormatError(): void
    {
        // GIVEN #[Format('uri')] on HttpNode::$url
        $processor = new WorkflowProcessor();
        $json = <<<'JSON'
            {
                "id": "wf",
                "name": "Bad URL",
                "nodes": [
                    {"type": "http", "id": "call", "url": "not a url"}
                ]
            }
            JSON;

        // WHEN
        try {
            $processor->load(Source::json($json));
            self::fail('Expected MappingFailed.');
        } catch (MappingFailed $exception) {
            // THEN
            $error = $exception->report()->errors[0];
            self::assertSame('mapping.format', $error->code);
            self::assertSame('/nodes/0/url', $error->pointer->toString());
        }
    }

    public function testNodePayloadViolatingConstraintsIsRejected(): void
    {
        // GIVEN node payloads breaking the declared #[Constraints]: an unknown
        // HTTP method, a timeout off the half-second grid, a zero delay
        $processor = new WorkflowProcessor();
        $json = <<<'JSON'
            {
                "id": "wf",
                "name": "Broken payloads",
                "nodes": [
                    {"type": "http", "id": "call", "url": "https://api.example.com", "method": "FETCH", "timeoutSeconds": 0.3},
                    {"type": "delay", "id": "wait", "seconds": 0}
                ]
            }
            JSON;

        // WHEN
        try {
            $processor->load(Source::json($json));
            self::fail('Expected MappingFailed.');
        } catch (MappingFailed $exception) {
            // THEN every violation reports at its exact location
            $codes = [];

            foreach ($exception->report() as $error) {
                $codes[$error->pointer->toString()][] = $error->code;
            }

            self::assertContains('mapping.pattern', $codes['/nodes/0/method']);
            self::assertContains('mapping.multiple_of', $codes['/nodes/0/timeoutSeconds']);
            self::assertContains('mapping.minimum', $codes['/nodes/1/seconds']);
        }
    }

    public function testWorkflowViolatingTheMetaSchemaIsRejected(): void
    {
        // GIVEN a definition missing its required "name"
        $processor = new WorkflowProcessor();

        // WHEN
        try {
            $processor->load(Source::json('{"id": "wf", "nodes": []}'));
            self::fail('Expected MappingFailed.');
        } catch (MappingFailed $exception) {
            // THEN
            self::assertSame('schema.required', $exception->report()->errors[0]->code);
        }
    }

    public function testGeneratedWorkflowSchemaCarriesTheConstraints(): void
    {
        // GIVEN the node types a bootstrap would register — the union is
        // open, so the generator needs the same registry the mapper uses
        $registry = new VariantRegistry();
        $registry->register(Node::class, 'http', HttpNode::class);
        $registry->register(Node::class, 'delay', DelayNode::class);
        $schema = new SchemaGenerator(variants: $registry)->generate(Workflow::class);

        // WHEN
        $document = json_decode(json_encode($schema->document, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $defs = $document['$defs'];
        self::assertIsArray($defs);

        // THEN the node payload constraints land in the generated JSON Schema
        $http = self::properties($defs['Ingot.Examples.Workflow.Definition.HttpNode'] ?? null);
        self::assertSame(
            ['type' => 'string', 'pattern' => '^(GET|POST|PUT|PATCH|DELETE)$'],
            $http['method'],
        );
        self::assertSame(
            ['type' => 'number', 'exclusiveMinimum' => 0, 'exclusiveMaximum' => 300, 'multipleOf' => 0.5],
            $http['timeoutSeconds'],
        );
        self::assertSame(
            ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'minProperties' => 1, 'maxProperties' => 20],
            $http['headers'],
        );

        $delay = self::properties($defs['Ingot.Examples.Workflow.Definition.DelayNode'] ?? null);
        self::assertSame(
            ['type' => 'integer', 'minimum' => 1, 'maximum' => 86400],
            $delay['seconds'],
        );

        $workflow = self::properties($defs['Ingot.Examples.Workflow.Definition.Workflow'] ?? null);
        self::assertSame(
            ['type' => 'string', 'minLength' => 1, 'pattern' => '^[a-z][a-z0-9-]*$'],
            $workflow['id'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function properties(mixed $def): array
    {
        self::assertIsArray($def);
        self::assertIsArray($def['properties'] ?? null);

        /** @var array<string, mixed> */
        return $def['properties'];
    }

    public function testWorkflowRoundTripsLosslesslyIncludingUnknownNodesAndVendorKeys(): void
    {
        // GIVEN
        $processor = new WorkflowProcessor();
        $original = file_get_contents(self::EXAMPLE_WORKFLOW);
        self::assertIsString($original);

        // WHEN load → save
        $saved = $processor->save($processor->load(Source::json($original)));

        // THEN nothing was lost — not the "webhook" node, not the x-vendor key
        self::assertEquals(
            json_decode($original, true, flags: \JSON_THROW_ON_ERROR),
            json_decode($saved, true, flags: \JSON_THROW_ON_ERROR),
        );
    }
}
