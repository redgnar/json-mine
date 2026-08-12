<?php

declare(strict_types=1);

namespace Ingot\Mapping;

use Ingot\Coercion;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;
use Ingot\Mapping\Metadata\ClassMetadata;
use Ingot\Mapping\Metadata\MetadataFactory;
use Ingot\Mapping\Type\ClassType;
use Ingot\Mapping\Type\DateTimeType;
use Ingot\Mapping\Type\EnumType;
use Ingot\Mapping\Type\ListType;
use Ingot\Mapping\Type\MapType;
use Ingot\Mapping\Type\MixedType;
use Ingot\Mapping\Type\NullableType;
use Ingot\Mapping\Type\ScalarKind;
use Ingot\Mapping\Type\ScalarType;
use Ingot\Mapping\Type\TypeNode;

/**
 * Recursive hydration engine: decoded JSON value + type tree → typed PHP value.
 *
 * Errors do not abort the run — failed subtrees return the {@see Failed}
 * sentinel and siblings keep mapping, so one pass reports every problem.
 * Successfully constructed objects are recorded (with their document paths)
 * in construction order, which is post-order — the order semantic validators
 * run in.
 *
 * @internal
 */
final class Hydrator
{
    /** @var list<MappingError> */
    private array $errors = [];

    /** @var list<array{object, JsonPointer}> */
    private array $objects = [];

    public function __construct(
        private readonly MetadataFactory $metadata,
        private readonly VariantRegistry $variants,
        private readonly Coercion $coercion,
        private readonly bool $trackObjects,
    ) {}

    /**
     * @return array{mixed, list<MappingError>, list<array{object, JsonPointer}>}
     */
    public function hydrate(TypeNode $type, mixed $value): array
    {
        $this->errors = [];
        $this->objects = [];

        $result = $this->value($type, $value, JsonPointer::root());

        return [$result, $this->errors, $this->objects];
    }

    private function value(TypeNode $type, mixed $value, JsonPointer $path): mixed
    {
        // The match is exhaustive over all shipped TypeNode implementations; a
        // foreign implementation fails fast with UnhandledMatchError.
        // @phpstan-ignore match.unhandled
        return match (true) {
            $type instanceof MixedType => $value,
            $type instanceof NullableType => $value === null ? null : $this->value($type->inner, $value, $path),
            $type instanceof ScalarType => $this->scalar($type->kind, $value, $path),
            $type instanceof EnumType => $this->enum($type, $value, $path),
            $type instanceof DateTimeType => $this->dateTime($value, $path),
            $type instanceof ListType => $this->list($type, $value, $path),
            $type instanceof MapType => $this->map($type, $value, $path),
            $type instanceof ClassType => $this->object($type->class, $value, $path),
        };
    }

    private function scalar(ScalarKind $kind, mixed $value, JsonPointer $path): mixed
    {
        $matched = match ($kind) {
            ScalarKind::Integer => \is_int($value),
            ScalarKind::Float => \is_float($value) || \is_int($value),
            ScalarKind::String => \is_string($value),
            ScalarKind::Boolean => \is_bool($value),
        };

        if ($matched) {
            return \is_int($value) && $kind === ScalarKind::Float ? (float) $value : $value;
        }

        if ($this->coercion === Coercion::Lax) {
            $coerced = $this->coerce($kind, $value);

            if ($coerced !== Failed::Value) {
                return $coerced;
            }
        }

        return $this->fail($path, 'mapping.type', \sprintf('Expected %s, got %s.', $kind->label(), get_debug_type($value)), $value);
    }

    /**
     * The documented Lax coercion table. JSON is text-sourced, so numeric
     * strings convert to numbers, scalars stringify, and 0/1 map to booleans.
     */
    private function coerce(ScalarKind $kind, mixed $value): mixed
    {
        switch ($kind) {
            case ScalarKind::Integer:
                if (\is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
                    return (int) $value;
                }

                break;
            case ScalarKind::Float:
                if (\is_string($value) && is_numeric($value)) {
                    return (float) $value;
                }

                break;
            case ScalarKind::String:
                if (\is_int($value) || \is_float($value)) {
                    return (string) $value;
                }

                break;
            case ScalarKind::Boolean:
                if ($value === 'true' || $value === 1) {
                    return true;
                }

                if ($value === 'false' || $value === 0) {
                    return false;
                }

                break;
        }

        return Failed::Value;
    }

    private function enum(EnumType $type, mixed $value, JsonPointer $path): mixed
    {
        foreach ($type->enum::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        $allowed = implode(', ', array_map(
            static fn(\BackedEnum $case): string => var_export($case->value, true),
            $type->enum::cases(),
        ));

        return $this->fail($path, 'mapping.enum', \sprintf('Not a valid value — allowed: %s.', $allowed), $value);
    }

    private function dateTime(mixed $value, JsonPointer $path): mixed
    {
        if (!\is_string($value)) {
            return $this->fail($path, 'mapping.type', \sprintf('Expected a date-time string, got %s.', get_debug_type($value)), $value);
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return $this->fail($path, 'mapping.format', \sprintf('"%s" is not a valid date-time.', $value), $value);
        }
    }

    private function list(ListType $type, mixed $value, JsonPointer $path): mixed
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return $this->fail($path, 'mapping.type', \sprintf('Expected a JSON array, got %s.', get_debug_type($value)), $value);
        }

        $errorsBefore = \count($this->errors);
        $items = [];

        foreach ($value as $index => $item) {
            $hydrated = $this->value($type->item, $item, $path->append($index));

            if ($hydrated !== Failed::Value) {
                $items[] = $hydrated;
            }
        }

        return \count($this->errors) > $errorsBefore ? Failed::Value : $items;
    }

    private function map(MapType $type, mixed $value, JsonPointer $path): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        if (!\is_array($value)) {
            return $this->fail($path, 'mapping.type', \sprintf('Expected a JSON object or array, got %s.', get_debug_type($value)), $value);
        }

        $errorsBefore = \count($this->errors);
        $entries = [];

        foreach ($value as $key => $item) {
            $hydrated = $this->value($type->value, $item, $path->append($key));

            if ($hydrated !== Failed::Value) {
                $entries[$key] = $hydrated;
            }
        }

        return \count($this->errors) > $errorsBefore ? Failed::Value : $entries;
    }

    /**
     * @param class-string $class
     * @param ?string $consumedField a JSON key already consumed upstream
     *        (the discriminator) — never reported as unexpected
     */
    private function object(string $class, mixed $value, JsonPointer $path, ?string $consumedField = null): mixed
    {
        if ($class === \stdClass::class) {
            return $value instanceof \stdClass
                ? $value
                : $this->fail($path, 'mapping.type', \sprintf('Expected a JSON object, got %s.', get_debug_type($value)), $value);
        }

        $metadata = $this->metadata->for($class);
        $variants = [...$metadata->discriminatorMap, ...$this->variants->variantsFor($class)];

        if ($metadata->discriminatorField !== null || $variants !== []) {
            return $this->unionVariant($class, $metadata, $variants, $value, $path);
        }

        if (!$metadata->isInstantiable) {
            throw new \LogicException(\sprintf(
                'Cannot map to %s — it is not instantiable and declares no #[Discriminator]. Register variants or map to a concrete class.',
                $class,
            ));
        }

        return $this->concrete($metadata, $value, $path, $consumedField);
    }

    /**
     * @param class-string $class
     * @param array<string, class-string> $variants
     */
    private function unionVariant(string $class, ClassMetadata $metadata, array $variants, mixed $value, JsonPointer $path): mixed
    {
        $field = $metadata->discriminatorField;

        if ($field === null) {
            throw new \LogicException(\sprintf(
                'Variants are registered for %s but the class declares no #[Discriminator] — the mapper cannot know which JSON field selects the variant.',
                $class,
            ));
        }

        if (!$value instanceof \stdClass) {
            return $this->fail($path, 'mapping.type', \sprintf('Expected a JSON object, got %s.', get_debug_type($value)), $value);
        }

        if (!property_exists($value, $field)) {
            return $this->fail($path, 'mapping.discriminator.missing', \sprintf('Missing discriminator field "%s".', $field), $value);
        }

        $selector = $value->{$field};

        if (!\is_string($selector)) {
            return $this->fail(
                $path->append($field),
                'mapping.type',
                \sprintf('Expected the discriminator to be a string, got %s.', get_debug_type($selector)),
                $selector,
            );
        }

        $variant = $variants[$selector] ?? $this->variants->fallbackFor($class);

        if ($variant === null) {
            $known = implode(', ', array_map(static fn(string $known): string => '"' . $known . '"', array_keys($variants)));

            return $this->fail(
                $path->append($field),
                'mapping.unknown_variant',
                \sprintf('Unknown variant "%s" — known variants: %s.', $selector, $known),
                $selector,
            );
        }

        return $this->object($variant, $value, $path, $field);
    }

    private function concrete(ClassMetadata $metadata, mixed $value, JsonPointer $path, ?string $consumedField = null): mixed
    {
        if (!$value instanceof \stdClass) {
            return $this->fail($path, 'mapping.type', \sprintf('Expected a JSON object, got %s.', get_debug_type($value)), $value);
        }

        $remaining = get_object_vars($value);
        $arguments = [];
        $propertyValues = [];
        $failed = false;

        foreach ($metadata->parameters as $parameter) {
            if ($parameter->isExtras) {
                continue; // receives whatever is left in $remaining, below
            }

            if (!\array_key_exists($parameter->jsonKey, $remaining)) {
                if ($parameter->hasDefault) {
                    $arguments[$parameter->name] = $parameter->default;
                } elseif ($parameter->type instanceof NullableType) {
                    $arguments[$parameter->name] = null;
                } else {
                    $this->fail($path, 'mapping.missing_key', \sprintf('Missing key "%s".', $parameter->jsonKey), null);
                    $failed = true;
                }

                continue;
            }

            $raw = $remaining[$parameter->jsonKey];
            unset($remaining[$parameter->jsonKey]);

            $hydrated = $this->value($parameter->type, $raw, $path->append($parameter->jsonKey));

            if ($hydrated === Failed::Value) {
                $failed = true;

                continue;
            }

            $arguments[$parameter->name] = $hydrated;
        }

        // Members the constructor does not cover are set after construction.
        foreach ($metadata->properties as $property) {
            if ($property->isExtras) {
                continue; // receives whatever is left in $remaining, below
            }

            if (!\array_key_exists($property->jsonKey, $remaining)) {
                if ($property->hasDefault) {
                    continue; // the declared default stays in place
                }

                if ($property->type instanceof NullableType) {
                    $propertyValues[$property->name] = null;
                } else {
                    $this->fail($path, 'mapping.missing_key', \sprintf('Missing key "%s".', $property->jsonKey), null);
                    $failed = true;
                }

                continue;
            }

            $raw = $remaining[$property->jsonKey];
            unset($remaining[$property->jsonKey]);

            $hydrated = $this->value($property->type, $raw, $path->append($property->jsonKey));

            if ($hydrated === Failed::Value) {
                $failed = true;

                continue;
            }

            $propertyValues[$property->name] = $hydrated;
        }

        if ($consumedField !== null) {
            unset($remaining[$consumedField]); // consumed upstream when selecting the variant
        }

        $extrasParameter = $metadata->extrasParameter();
        $extrasProperty = $metadata->extrasProperty();

        if ($extrasParameter !== null) {
            $arguments[$extrasParameter->name] = $remaining;
        } elseif ($extrasProperty !== null) {
            $propertyValues[$extrasProperty->name] = $remaining;
        } elseif ($remaining !== [] && $this->coercion === Coercion::Strict) {
            foreach ($remaining as $key => $unexpected) {
                $this->fail($path->append($key), 'mapping.unexpected_key', \sprintf('Unexpected key "%s".', $key), $unexpected);
            }

            $failed = true;
        }

        if ($failed) {
            return Failed::Value;
        }

        return $this->construct($metadata, $arguments, $propertyValues, $path);
    }

    /**
     * @param array<string, mixed> $arguments keyed by parameter name
     * @param array<string, mixed> $propertyValues keyed by property name, set after construction
     */
    private function construct(ClassMetadata $metadata, array $arguments, array $propertyValues, JsonPointer $path): mixed
    {
        $ordered = [];

        foreach ($metadata->parameters as $parameter) {
            $ordered[] = $arguments[$parameter->name];
        }

        try {
            $object = new ($metadata->class)(...$ordered);
        } catch (\Exception $exception) {
            return $this->fail($path, 'mapping.constructor', $exception->getMessage(), null);
        }

        if ($propertyValues !== []) {
            $reflection = new \ReflectionClass($metadata->class);

            foreach ($propertyValues as $name => $propertyValue) {
                try {
                    $reflection->getProperty($name)->setValue($object, $propertyValue);
                } catch (\Error $error) {
                    // e.g. a readonly property the constructor already initialized
                    return $this->fail($path, 'mapping.property', \sprintf('Cannot set property "%s": %s', $name, $error->getMessage()), $propertyValue);
                }
            }
        }

        if ($this->trackObjects) {
            $this->objects[] = [$object, $path];
        }

        return $object;
    }

    private function fail(JsonPointer $path, string $code, string $message, mixed $input): Failed
    {
        $this->errors[] = new MappingError($path, $code, $message, $input);

        return Failed::Value;
    }
}
