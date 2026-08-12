<?php

declare(strict_types=1);

namespace Ingot\Benchmarks;

use Ingot\MapperBuilder;
use Ingot\Schema\Schema;
use Ingot\Source;
use Ingot\Tests\Fixture\FormDefinition;
use Ingot\TreeMapper;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;

/**
 * Baseline numbers for the hot path: hydrating a large form definition
 * (discriminated unions, nested lists) with a mapper built once.
 */
#[BeforeMethods('setUp')]
final class MapperBench
{
    private const int FIELDS = 200;

    private TreeMapper $mapper;

    private TreeMapper $mapperWithSchema;

    private string $json;

    private string $schemaJson;

    public function setUp(): void
    {
        $fields = [];

        for ($i = 0; $i < self::FIELDS; ++$i) {
            $fields[] = $i % 2 === 0
                ? ['type' => 'text', 'name' => 'field_' . $i, 'maxLength' => 120]
                : ['type' => 'select', 'name' => 'field_' . $i, 'options' => ['a', 'b', 'c']];
        }

        $this->json = json_encode(['id' => 'form-big', 'fields' => $fields], \JSON_THROW_ON_ERROR);

        $this->mapper = MapperBuilder::create()->build();

        $this->schemaJson = <<<'JSON'
            {
                "type": "object",
                "required": ["id", "fields"],
                "properties": {
                    "id": {"type": "string"},
                    "fields": {
                        "type": "array",
                        "items": {
                            "type": "object",
                            "required": ["type", "name"],
                            "properties": {
                                "type": {"type": "string"},
                                "name": {"type": "string"}
                            }
                        }
                    }
                }
            }
            JSON;

        $this->mapperWithSchema = MapperBuilder::create()
            ->withSchema(FormDefinition::class, Schema::fromJson($this->schemaJson))
            ->build();
    }

    #[Revs(50)]
    #[Iterations(5)]
    public function benchHydrateLargeForm(): void
    {
        $this->mapper->map(FormDefinition::class, Source::json($this->json));
    }

    #[Revs(50)]
    #[Iterations(5)]
    public function benchHydrateLargeFormWithSchemaPreCheck(): void
    {
        $this->mapperWithSchema->map(FormDefinition::class, Source::json($this->json));
    }

    #[Revs(50)]
    #[Iterations(5)]
    public function benchJsonDecodeOnlyBaseline(): void
    {
        json_decode($this->json, false, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * Worst-case schema usage: a fresh Schema instance on every call
     * (per-request construction) — exercises the content-canonicalization
     * pool in OpisSchemaValidator.
     */
    #[Revs(50)]
    #[Iterations(5)]
    public function benchHydrateWithFreshSchemaInstanceEachTime(): void
    {
        $source = Source::json($this->json)->withSchema(Schema::fromJson($this->schemaJson));
        $this->mapper->map(FormDefinition::class, $source);
    }
}
