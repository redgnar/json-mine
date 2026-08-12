<?php

declare(strict_types=1);

namespace Ingot\Examples\Forms;

use Ingot\Examples\Forms\Definition\Field;
use Ingot\Examples\Forms\Definition\FormDefinition;
use Ingot\Examples\Forms\Definition\NumberField;
use Ingot\Examples\Forms\Definition\SelectField;
use Ingot\Examples\Forms\Definition\TextField;
use Ingot\Schema\Schema;

/**
 * Derives the JSON Schema of a form's *values* from its definition — the
 * pattern from the design docs: the definition is the source of truth, the
 * data schema is a generated artifact. The same schema can validate
 * submissions in PHP and in the browser (Ajv), and documents stored data.
 */
final class DataSchemaDeriver
{
    public function derive(FormDefinition $definition): Schema
    {
        $properties = [];
        $required = [];

        foreach ($definition->fields as $field) {
            $properties[$field->name] = $this->fieldSchema($field);

            if ($field->required) {
                $required[] = $field->name;
            }
        }

        $document = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => \sprintf('Values of form "%s"', $definition->id),
            'type' => 'object',
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'additionalProperties' => false,
        ];

        if ($required !== []) {
            $document['required'] = $required;
        }

        return Schema::fromJson(json_encode($document, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>|\stdClass
     */
    private function fieldSchema(Field $field): array|\stdClass
    {
        if ($field instanceof TextField) {
            $schema = ['type' => 'string'];

            if ($field->required) {
                $schema['minLength'] = 1; // required means non-empty, not merely present
            }

            if ($field->maxLength !== null) {
                $schema['maxLength'] = $field->maxLength;
            }

            if ($field->pattern !== null) {
                $schema['pattern'] = $field->pattern;
            }

            return $schema;
        }

        if ($field instanceof SelectField) {
            return ['enum' => $field->options];
        }

        if ($field instanceof NumberField) {
            $schema = ['type' => 'number'];

            if ($field->min !== null) {
                $schema['minimum'] = $field->min;
            }

            if ($field->max !== null) {
                $schema['maximum'] = $field->max;
            }

            return $schema;
        }

        // Unknown (plugin) field types accept anything — their plugin owns
        // the value contract.
        return new \stdClass();
    }
}
