<?php

declare(strict_types=1);

namespace JsonMine\SchemaGen;

use JsonMine\Mapping\Metadata\MetadataFactory;
use JsonMine\Mapping\Type\ClassType;
use JsonMine\Mapping\Type\DateTimeType;
use JsonMine\Mapping\Type\EnumType;
use JsonMine\Mapping\Type\ListType;
use JsonMine\Mapping\Type\MapType;
use JsonMine\Mapping\Type\MixedType;
use JsonMine\Mapping\Type\NullableType;
use JsonMine\Mapping\Type\ScalarKind;
use JsonMine\Mapping\Type\ScalarType;
use JsonMine\Mapping\Type\TypeNode;
use JsonMine\Mapping\Type\TypeParser;
use JsonMine\Mapping\VariantRegistry;
use JsonMine\Schema\Schema;

/**
 * Generates JSON Schema (draft 2020-12) from mapping targets, reading the
 * same metadata the hydrator and normalizer use — the schema cannot drift
 * from the actual (de)serialization behavior.
 *
 * Shapes mirror the mapper's semantics:
 * - required = members without a default that are not nullable,
 * - additionalProperties = true only for classes with an #[Extras] bag
 *   (matching strict-mode unexpected-key detection),
 * - discriminated unions emit anyOf over the variants, each variant carrying
 *   its discriminator as a const (fallback variants are not part of the
 *   schema — an unknown variant is schema-invalid, like in a strict engine),
 * - classes become $defs entries referenced via $ref, which makes recursive
 *   types work.
 */
final class SchemaGenerator
{
    public function __construct(
        private readonly MetadataFactory $metadata = new MetadataFactory(),
        private readonly TypeParser $parser = new TypeParser(),
        private readonly VariantRegistry $variants = new VariantRegistry(),
    ) {}

    /**
     * @param class-string|string $target a class name or a type string ('list<Field>')
     */
    public function generate(string $target): Schema
    {
        $defs = [];
        $root = $this->node($this->parser->parse($target), $defs);

        $document = ['$schema' => 'https://json-schema.org/draft/2020-12/schema', ...$root];

        if ($defs !== []) {
            $document['$defs'] = $defs;
        }

        return Schema::fromJson(json_encode($document, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $defs
     *
     * @return array<string, mixed>
     */
    private function node(TypeNode $type, array &$defs): array
    {
        // @phpstan-ignore match.unhandled (exhaustive over shipped TypeNode implementations)
        return match (true) {
            $type instanceof MixedType => [],
            $type instanceof ScalarType => ['type' => match ($type->kind) {
                ScalarKind::Integer => 'integer',
                ScalarKind::Float => 'number',
                ScalarKind::String => 'string',
                ScalarKind::Boolean => 'boolean',
            }],
            $type instanceof NullableType => ['anyOf' => [$this->embed($this->node($type->inner, $defs)), ['type' => 'null']]],
            // Backed enums are JsonSerializable — cases encode to their values.
            $type instanceof EnumType => ['enum' => $type->enum::cases()],
            $type instanceof DateTimeType => ['type' => 'string', 'format' => 'date-time'],
            $type instanceof ListType => ['type' => 'array', 'items' => $this->embed($this->node($type->item, $defs))],
            $type instanceof MapType => ['type' => 'object', 'additionalProperties' => $this->embed($this->node($type->value, $defs))],
            $type instanceof ClassType => $this->reference($type->class, $defs),
        };
    }

    /**
     * An empty fragment means "anything" — embedded positions need `true`
     * (an empty PHP array would encode as [], which is not a schema).
     *
     * @param array<string, mixed> $fragment
     *
     * @return array<string, mixed>|bool
     */
    private function embed(array $fragment): array|bool
    {
        return $fragment === [] ? true : $fragment;
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $defs
     *
     * @return array<string, mixed>
     */
    private function reference(string $class, array &$defs): array
    {
        $name = str_replace('\\', '.', $class);

        if (!\array_key_exists($name, $defs)) {
            $defs[$name] = []; // placeholder guards against infinite recursion
            $defs[$name] = $this->classDef($class, $defs);
        }

        return ['$ref' => \sprintf('#/$defs/%s', $name)];
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $defs
     *
     * @return array<string, mixed>
     */
    private function classDef(string $class, array &$defs): array
    {
        $metadata = $this->metadata->for($class);
        $variants = [...$metadata->discriminatorMap, ...$this->variants->variantsFor($class)];

        if ($metadata->discriminatorField !== null && $variants !== []) {
            $anyOf = [];

            foreach ($variants as $selector => $variant) {
                $anyOf[] = $this->reference($variant, $defs);
                $this->injectDiscriminator($defs, $variant, $metadata->discriminatorField, $selector);
            }

            return ['anyOf' => $anyOf];
        }

        $properties = [];
        $required = [];

        foreach ($metadata->parameters as $parameter) {
            if ($parameter->isExtras) {
                continue;
            }

            $properties[$parameter->jsonKey] = $this->embed($this->node($parameter->type, $defs));

            if (!$parameter->hasDefault && !$parameter->type instanceof NullableType) {
                $required[] = $parameter->jsonKey;
            }
        }

        foreach ($metadata->properties as $member) {
            if ($member->isExtras) {
                continue;
            }

            $properties[$member->jsonKey] = $this->embed($this->node($member->type, $defs));

            if (!$member->hasDefault && !$member->type instanceof NullableType) {
                $required[] = $member->jsonKey;
            }
        }

        $def = ['type' => 'object'];

        if ($properties !== []) {
            $def['properties'] = $properties;
        }

        if ($required !== []) {
            $def['required'] = $required;
        }

        $def['additionalProperties'] = $metadata->extrasParameter() !== null || $metadata->extrasProperty() !== null;

        return $def;
    }

    /**
     * A union variant's serialized form carries the discriminator field —
     * the schema states it as a required const.
     *
     * @param array<string, mixed> $defs
     * @param class-string $variant
     */
    private function injectDiscriminator(array &$defs, string $variant, string $field, string $selector): void
    {
        $name = str_replace('\\', '.', $variant);
        $def = $defs[$name];

        if (!\is_array($def)) {
            return;
        }

        if (!\array_key_exists('type', $def)) {
            return; // a recursion placeholder or itself a union — nothing to inject into
        }

        /** @var array<string, mixed> $def */
        $properties = \is_array($def['properties'] ?? null) ? $def['properties'] : [];
        $required = \is_array($def['required'] ?? null) ? $def['required'] : [];

        $def['properties'] = [$field => ['const' => $selector], ...$properties];
        $def['required'] = [$field, ...$required];

        $defs[$name] = $def;
    }
}
