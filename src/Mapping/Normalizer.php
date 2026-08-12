<?php

declare(strict_types=1);

namespace Ingot\Mapping;

use Ingot\Mapping\Metadata\ClassMetadata;
use Ingot\Mapping\Metadata\MetadataFactory;
use Ingot\Mapping\Type\NullableType;
use Ingot\Mapping\Type\TypeNode;

/**
 * The reverse of hydration: typed PHP values → json_encode-ready data,
 * driven by the same metadata the hydrator reads (zero drift).
 *
 * - constructor parameters and non-constructor members emit under their
 *   JSON keys (#[Name] honored),
 * - the #[Extras] bag merges back flat — unknown fields survive the
 *   round-trip untouched (raw \stdClass fragments pass through as-is),
 * - discriminated-union variants re-emit their discriminator field, which
 *   hydration consumed when selecting the variant,
 * - backed enums emit their value, \DateTimeInterface emits RFC 3339.
 *
 * @internal
 */
final class Normalizer
{
    public function __construct(
        private readonly MetadataFactory $metadata,
        private readonly VariantRegistry $variants,
    ) {}

    public function normalize(mixed $value): mixed
    {
        /** @var \SplObjectStorage<object, null> $path */
        $path = new \SplObjectStorage();

        return $this->convert($value, $path);
    }

    /**
     * @param \SplObjectStorage<object, null> $path objects on the current descent path (cycle guard)
     */
    private function convert(mixed $value, \SplObjectStorage $path): mixed
    {
        if ($value === null || \is_scalar($value)) {
            return $value;
        }

        if (\is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->convert($item, $path);
            }

            return $normalized;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::RFC3339);
        }

        if ($value instanceof \stdClass) {
            return $value; // a raw JSON fragment (e.g. inside an extras bag)
        }

        if (!\is_object($value)) {
            throw new \LogicException(\sprintf('Cannot normalize a value of type %s.', get_debug_type($value)));
        }

        return $this->object($value, $path);
    }

    /**
     * @param \SplObjectStorage<object, null> $path
     *
     * @return array<string, mixed>|\stdClass
     */
    private function object(object $value, \SplObjectStorage $path): array|\stdClass
    {
        if ($path->contains($value)) {
            throw new \LogicException(\sprintf('Cannot normalize %s — the object graph contains a cycle.', $value::class));
        }

        $path->attach($value);
        $metadata = $this->metadata->for($value::class);
        $reflection = new \ReflectionClass($value::class);
        $result = $this->discriminatorEntry($value::class);

        foreach ($metadata->parameters as $parameter) {
            if ($parameter->isExtras || !$reflection->hasProperty($parameter->name)) {
                continue; // a constructor-only parameter leaves nothing to read back
            }

            $property = $reflection->getProperty($parameter->name);

            if (!$property->isInitialized($value)) {
                continue;
            }

            $memberValue = $property->getValue($value);

            if ($this->isOmittable($memberValue, $parameter->hasDefault, $parameter->default, $parameter->type)) {
                continue;
            }

            $result[$parameter->jsonKey] = $this->convert($memberValue, $path);
        }

        foreach ($metadata->properties as $member) {
            if ($member->isExtras) {
                continue;
            }

            $property = $reflection->getProperty($member->name);

            if (!$property->isInitialized($value)) {
                continue;
            }

            $memberValue = $property->getValue($value);

            if ($this->isOmittable($memberValue, $member->hasDefault, $member->default, $member->type)) {
                continue;
            }

            $result[$member->jsonKey] = $this->convert($memberValue, $path);
        }

        foreach ($this->extras($metadata, $reflection, $value) as $key => $extra) {
            $result[$key] = $this->convert($extra, $path);
        }

        $path->detach($value); // sharing an object between branches is legal, only true cycles are not

        return $result === [] ? new \stdClass() : $result;
    }

    /**
     * A member is omitted when hydration would restore it from the missing
     * key anyway: values equal to the declared default, and nulls in nullable
     * members without a default. Keeps round-trips minimal — normalize()
     * never invents keys the source document did not have.
     */
    private function isOmittable(mixed $memberValue, bool $hasDefault, mixed $default, TypeNode $type): bool
    {
        if ($hasDefault) {
            return $memberValue === $default;
        }

        return $memberValue === null && $type instanceof NullableType;
    }

    /**
     * The discriminator entry for a union variant: hydration consumed the
     * field when selecting the variant, so serialization must re-emit it.
     * Ancestors (parent classes and interfaces) are searched for a
     * #[Discriminator] whose map — or the runtime registry — names this class.
     *
     * @param class-string $class
     *
     * @return array<string, string>
     */
    private function discriminatorEntry(string $class): array
    {
        foreach ([...class_parents($class), ...class_implements($class)] as $ancestor) {
            $metadata = $this->metadata->for($ancestor);

            if ($metadata->discriminatorField === null) {
                continue;
            }

            $variants = [...$metadata->discriminatorMap, ...$this->variants->variantsFor($ancestor)];
            $selector = array_search($class, $variants, true);

            if ($selector !== false) {
                return [$metadata->discriminatorField => $selector];
            }
        }

        return [];
    }

    /**
     * @param \ReflectionClass<object> $reflection
     *
     * @return array<string, mixed>
     */
    private function extras(ClassMetadata $metadata, \ReflectionClass $reflection, object $value): array
    {
        $bag = $metadata->extrasParameter()->name ?? $metadata->extrasProperty()?->name;

        if ($bag === null) {
            return [];
        }

        $property = $reflection->getProperty($bag);

        if (!$property->isInitialized($value)) {
            return [];
        }

        $extras = $property->getValue($value);

        if (!\is_array($extras)) {
            return [];
        }

        /** @var array<string, mixed> $extras */
        return $extras;
    }
}
