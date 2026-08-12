<?php

declare(strict_types=1);

namespace Ingot\Tests;

use Ingot\MappingResult;
use Ingot\MappingSuccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MappingSuccess::class)]
final class MappingSuccessTest extends TestCase
{
    public function testExposesTheMappedValue(): void
    {
        // GIVEN
        $mapped = new \stdClass();
        $result = $this->successResult($mapped);

        // WHEN
        $value = $result->value();

        // THEN
        self::assertTrue($result->isSuccess());
        self::assertSame($mapped, $value);
    }

    /**
     * Typed as the interface — the way callers of tryMap() see the result.
     *
     * @return MappingResult<\stdClass>
     */
    private function successResult(\stdClass $mapped): MappingResult
    {
        return new MappingSuccess($mapped);
    }

    public function testHasAnEmptyErrorReport(): void
    {
        // GIVEN
        $result = new MappingSuccess(new \stdClass());

        // WHEN
        $report = $result->errors();

        // THEN
        self::assertTrue($report->isEmpty());
    }
}
