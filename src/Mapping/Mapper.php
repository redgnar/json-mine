<?php

declare(strict_types=1);

namespace JsonMine\Mapping;

use JsonMine\Coercion;
use JsonMine\Error\ErrorReport;
use JsonMine\Error\MappingError;
use JsonMine\JsonPointer;
use JsonMine\Mapping\Metadata\MetadataFactory;
use JsonMine\Mapping\Type\TypeParser;
use JsonMine\MappingFailure;
use JsonMine\MappingResult;
use JsonMine\MappingSuccess;
use JsonMine\Schema\SchemaValidator;
use JsonMine\Schema\SchemaVault;
use JsonMine\Source;
use JsonMine\TreeMapper;
use JsonMine\Validation\ValidationContext;

/**
 * The TreeMapper implementation: schema pre-check (per-call override or
 * vault) → hydration → semantic validators, one aggregated report.
 *
 * Built by {@see \JsonMine\MapperBuilder}; build once, map many times.
 *
 * @internal
 */
final readonly class Mapper implements TreeMapper
{
    private Normalizer $normalizer;

    public function __construct(
        private TypeParser $parser,
        private MetadataFactory $metadata,
        private VariantRegistry $variants,
        private ValidatorRegistry $validators,
        private SchemaValidator $schemaValidator,
        private SchemaVault $vault,
        private Coercion $coercion,
    ) {
        $this->normalizer = new Normalizer($metadata, $variants);
    }

    public function map(string $target, Source $source): mixed
    {
        return $this->tryMap($target, $source)->value();
    }

    public function tryMap(string $target, Source $source): MappingResult
    {
        try {
            $data = $source->data();
        } catch (\JsonException $exception) {
            return new MappingFailure(ErrorReport::of(
                new MappingError(JsonPointer::root(), 'source.malformed_json', $exception->getMessage()),
            ));
        }

        $schemaReport = $this->schemaCheck($target, $source, $data);

        if (!$schemaReport->isEmpty()) {
            return new MappingFailure($schemaReport);
        }

        $type = $this->parser->parse($target);
        $hydrator = new Hydrator($this->metadata, $this->variants, $this->coercion, !$this->validators->isEmpty());
        [$value, $errors, $objects] = $hydrator->hydrate($type, $data);

        if ($errors !== []) {
            return new MappingFailure(ErrorReport::of(...$errors));
        }

        $semanticReport = $this->runValidators($objects, $value);

        if (!$semanticReport->isEmpty()) {
            return new MappingFailure($semanticReport);
        }

        // The interface promises MappingResult<T> for class-string<T> targets via a
        // conditional return type; the runtime bridge from a type string to T is not
        // provable to the type system.
        // @phpstan-ignore return.type
        return new MappingSuccess($value);
    }

    public function normalize(mixed $value): mixed
    {
        return $this->normalizer->normalize($value);
    }

    private function schemaCheck(string $target, Source $source, mixed $data): ErrorReport
    {
        $schema = $source->schemaOverride;

        if ($schema === null && (class_exists($target) || interface_exists($target))) {
            $schema = $this->vault->resolve($target, $data);
        }

        if ($schema === null) {
            return ErrorReport::none();
        }

        return $this->schemaValidator->validate($data, $schema);
    }

    /**
     * Runs bound validators over the recorded objects. The recording order is
     * construction order, which is post-order: children validate before their
     * parents and the document root validates last.
     *
     * @param list<array{object, JsonPointer}> $objects
     */
    private function runValidators(array $objects, mixed $root): ErrorReport
    {
        $report = ErrorReport::none();

        foreach ($objects as [$object, $path]) {
            foreach ($this->validators->for($object) as $validator) {
                $context = new ValidationContext($path, $root);
                $validator->validate($object, $context);
                $report = $report->merge($context->errors());
            }
        }

        return $report;
    }
}
