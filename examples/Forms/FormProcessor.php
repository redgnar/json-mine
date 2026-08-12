<?php

declare(strict_types=1);

namespace Ingot\Examples\Forms;

use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\Examples\Forms\Definition\Field;
use Ingot\Examples\Forms\Definition\FormDefinition;
use Ingot\Examples\Forms\Definition\GenericField;
use Ingot\Examples\Forms\Definition\UniqueFieldNamesValidator;
use Ingot\JsonPointer;
use Ingot\MapperBuilder;
use Ingot\Schema\OpisSchemaValidator;
use Ingot\Schema\Schema;
use Ingot\Schema\SchemaValidator;
use Ingot\Source;
use Ingot\Tree\JsonNode;
use Ingot\TreeMapper;

/**
 * The whole form pipeline wired together:
 *
 *   definition.json ─(meta-schema)→ FormDefinition ─(derive)→ data schema
 *   values.json ─(data schema)→ validated → JsonNode access
 *
 * plus the reverse direction: a definition normalizes back to JSON without
 * losing unknown (plugin) fields.
 */
final class FormProcessor
{
    private readonly TreeMapper $mapper;
    private readonly DataSchemaDeriver $deriver;
    private readonly SchemaValidator $dataValidator;

    public function __construct()
    {
        $this->mapper = MapperBuilder::create()
            ->withSchema(FormDefinition::class, Schema::fromFile(__DIR__ . '/form-definition.schema.json'))
            ->withValidator(FormDefinition::class, new UniqueFieldNamesValidator())
            ->withVariantFallback(Field::class, GenericField::class)
            ->build();
        $this->deriver = new DataSchemaDeriver();
        $this->dataValidator = new OpisSchemaValidator();
    }

    /**
     * @throws \Ingot\Error\MappingFailed with the aggregated report (meta-schema,
     *         type mapping, and semantic rules) when the definition is invalid
     */
    public function loadDefinition(Source $source): FormDefinition
    {
        return $this->mapper->map(FormDefinition::class, $source);
    }

    /**
     * The JSON Schema of this form's values — shippable to the frontend.
     */
    public function dataSchema(FormDefinition $definition): Schema
    {
        return $this->deriver->derive($definition);
    }

    /**
     * Validates submitted values against the definition. An empty report
     * means the submission is valid.
     */
    public function validateData(FormDefinition $definition, Source $submission): ErrorReport
    {
        try {
            $decoded = $submission->data();
        } catch (\JsonException $exception) {
            return ErrorReport::of(
                new MappingError(JsonPointer::root(), 'source.malformed_json', $exception->getMessage()),
            );
        }

        return $this->dataValidator->validate($decoded, $this->deriver->derive($definition));
    }

    /**
     * Typed access to validated submission values (form data has no classes —
     * its shape exists only in the definition).
     *
     * @throws \Ingot\Error\MappingFailed when the submission is not valid JSON
     */
    public function values(Source $submission): JsonNode
    {
        return JsonNode::of($submission);
    }

    /**
     * The definition back as JSON — lossless even for unknown field types.
     */
    public function saveDefinition(FormDefinition $definition): string
    {
        return json_encode(
            $this->mapper->normalize($definition),
            \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
        );
    }
}
