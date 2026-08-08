<?php

declare(strict_types=1);

namespace JsonMine\Tests\Validation;

use JsonMine\JsonPointer;
use JsonMine\Validation\ValidationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidationContext::class)]
final class ValidationContextTest extends TestCase
{
    public function testStartsWithAnEmptyReport(): void
    {
        // GIVEN
        $context = new ValidationContext(JsonPointer::root(), new \stdClass());

        // WHEN
        $report = $context->errors();

        // THEN
        self::assertTrue($report->isEmpty());
    }

    public function testResolvesRelativePointerAgainstTheObjectPath(): void
    {
        // GIVEN a validator running for the object at /nodes/3
        $context = new ValidationContext(JsonPointer::fromString('/nodes/3'), new \stdClass());

        // WHEN
        $context->addError('/connections/0', 'workflow.edge.dangling', 'Edge points to a missing node.', 'node-99');

        // THEN the reported location is absolute
        $error = $context->errors()->errors[0];
        self::assertSame('/nodes/3/connections/0', $error->pointer->toString());
        self::assertSame('workflow.edge.dangling', $error->code);
        self::assertSame('Edge points to a missing node.', $error->message);
        self::assertSame('node-99', $error->input);
    }

    public function testEmptyRelativePointerTargetsTheValidatedObjectItself(): void
    {
        // GIVEN
        $context = new ValidationContext(JsonPointer::fromString('/fields/1'), new \stdClass());

        // WHEN
        $context->addError('', 'form.field.duplicate-name', 'Field name is not unique.');

        // THEN
        self::assertSame('/fields/1', $context->errors()->errors[0]->pointer->toString());
    }

    public function testCollectsErrorsInReportingOrder(): void
    {
        // GIVEN
        $context = new ValidationContext(JsonPointer::root(), new \stdClass());

        // WHEN
        $context->addError('/a', 'rule.first', 'First.');
        $context->addError('/b', 'rule.second', 'Second.');

        // THEN
        $codes = array_map(static fn($error): string => $error->code, $context->errors()->errors);
        self::assertSame(['rule.first', 'rule.second'], $codes);
    }

    public function testExposesPathAndRootToValidators(): void
    {
        // GIVEN
        $path = JsonPointer::fromString('/nodes/3');
        $root = new \stdClass();
        $root->nodes = [];

        // WHEN
        $context = new ValidationContext($path, $root);

        // THEN cross-node rules can reach the whole document
        self::assertSame($path, $context->path());
        self::assertSame($root, $context->root());
    }
}
