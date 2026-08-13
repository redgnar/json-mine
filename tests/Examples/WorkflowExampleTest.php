<?php

declare(strict_types=1);

namespace Ingot\Tests\Examples;

use Ingot\Error\MappingFailed;
use Ingot\Examples\Workflow\Definition\DelayNode;
use Ingot\Examples\Workflow\Definition\GenericNode;
use Ingot\Examples\Workflow\Definition\HttpNode;
use Ingot\Examples\Workflow\WorkflowProcessor;
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
