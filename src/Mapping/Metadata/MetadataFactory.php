<?php

declare(strict_types=1);

namespace Ingot\Mapping\Metadata;

use Ingot\Attribute\Constraints;
use Ingot\Attribute\Discriminator;
use Ingot\Attribute\Extras;
use Ingot\Attribute\Format;
use Ingot\Attribute\Name;
use Ingot\Mapping\Type\ConstraintSet;
use Ingot\Mapping\Type\DateTimeType;
use Ingot\Mapping\Type\FormatKind;
use Ingot\Mapping\Type\ListType;
use Ingot\Mapping\Type\MapType;
use Ingot\Mapping\Type\MixedType;
use Ingot\Mapping\Type\NullableType;
use Ingot\Mapping\Type\ScalarKind;
use Ingot\Mapping\Type\ScalarType;
use Ingot\Mapping\Type\TypeNode;
use Ingot\Mapping\Type\TypeParser;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Builds (and caches) hydration metadata from reflection: constructor
 * parameters, native types refined by docblock `@param` types, and the
 * mapping attributes.
 *
 * Caching is two-level: an in-memory map always, plus an optional PSR-6 pool
 * for cross-request reuse. Pool keys derive from the class name only —
 * deployments changing class definitions must clear the pool (use an
 * in-memory pool during development).
 *
 * Configuration problems (unsupported types, misplaced attributes) throw
 * \LogicException — they are programmer errors, not data errors.
 */
final class MetadataFactory
{
    /** @var array<class-string, ClassMetadata> */
    private array $cache = [];

    public function __construct(
        private readonly TypeParser $parser = new TypeParser(),
        private readonly ?CacheItemPoolInterface $pool = null,
    ) {}

    /**
     * @param class-string $class
     */
    public function for(string $class): ClassMetadata
    {
        return $this->cache[$class] ??= $this->load($class);
    }

    /**
     * @param class-string $class
     */
    private function load(string $class): ClassMetadata
    {
        if ($this->pool === null) {
            return $this->build($class);
        }

        $item = $this->pool->getItem($this->cacheKey($class));

        if ($item->isHit()) {
            $cached = $item->get();

            if ($cached instanceof ClassMetadata) {
                return $cached;
            }
        }

        $metadata = $this->build($class);
        $this->pool->save($item->set($metadata));

        return $metadata;
    }

    /**
     * @param class-string $class
     */
    private function cacheKey(string $class): string
    {
        // Class names contain backslashes (reserved in PSR-6 keys) — hash them.
        return \sprintf('ingot.metadata.%s', hash('xxh128', $class));
    }

    /**
     * @param class-string $class
     */
    private function build(string $class): ClassMetadata
    {
        $reflection = new \ReflectionClass($class);

        [$discriminatorField, $discriminatorMap] = $this->discriminator($reflection);

        $constructor = $reflection->getConstructor();
        $parameters = [];
        $properties = [];

        if ($reflection->isInstantiable()) {
            $namespace = $reflection->getNamespaceName();

            if ($constructor !== null) {
                $docblockTypes = $this->docblockParameterTypes($constructor);

                foreach ($constructor->getParameters() as $parameter) {
                    $parameters[] = $this->parameterMetadata($parameter, $docblockTypes, $namespace, $class);
                }
            }

            $properties = $this->properties($reflection, $parameters, $namespace, $class);
        }

        return new ClassMetadata(
            $class,
            $parameters,
            $properties,
            $reflection->isInstantiable(),
            $discriminatorField,
            $discriminatorMap,
        );
    }

    /**
     * Collects members not covered by the constructor: non-static,
     * non-promoted properties whose names match no constructor parameter.
     *
     * @param \ReflectionClass<object> $reflection
     * @param list<ParameterMetadata> $parameters
     * @param class-string $class
     *
     * @return list<PropertyMetadata>
     */
    private function properties(\ReflectionClass $reflection, array $parameters, string $namespace, string $class): array
    {
        $parameterNames = array_map(static fn(ParameterMetadata $parameter): string => $parameter->name, $parameters);
        $properties = [];

        foreach ($reflection->getProperties() as $property) {
            // Promoted properties are covered by the parameter-name check —
            // a promoted property always shares its constructor parameter's name.
            if ($property->isStatic() || \in_array($property->getName(), $parameterNames, true)) {
                continue;
            }

            $properties[] = $this->propertyMetadata($property, $namespace, $class);
        }

        return $properties;
    }

    /**
     * @param class-string $class
     */
    private function propertyMetadata(\ReflectionProperty $property, string $namespace, string $class): PropertyMetadata
    {
        $jsonKey = $property->getName();
        $isExtras = false;
        $owner = \sprintf('property "%s" of %s', $property->getName(), $class);

        foreach ($property->getAttributes(Name::class) as $attribute) {
            $jsonKey = $attribute->newInstance()->key;
        }

        if ($property->getAttributes(Extras::class) !== []) {
            $isExtras = true;
        }

        $type = $this->typeOf($property->getType(), $this->varType($property), $namespace, $owner);

        foreach ($property->getAttributes(Format::class) as $attribute) {
            $type = $this->applyFormat($type, $attribute->newInstance()->format, $owner);
        }

        foreach ($property->getAttributes(Constraints::class) as $attribute) {
            $type = $this->applyConstraints($type, $attribute->newInstance(), $owner);
        }

        if ($isExtras && !($type instanceof MapType || $type instanceof MixedType)) {
            throw new \LogicException(\sprintf(
                'The #[Extras] property "%s" of %s must be an array.',
                $property->getName(),
                $class,
            ));
        }

        return new PropertyMetadata(
            $property->getName(),
            $jsonKey,
            $type,
            $property->hasDefaultValue(),
            $property->hasDefaultValue() ? $property->getDefaultValue() : null,
            $isExtras,
        );
    }

    /**
     * Extracts the type string of a `@var` declaration from the property docblock.
     */
    private function varType(\ReflectionProperty $property): ?string
    {
        $docblock = $property->getDocComment();

        if ($docblock === false || preg_match('/@var\s+(.+)/', $docblock, $matches) !== 1) {
            return null;
        }

        return $this->firstTypeToken($matches[1]);
    }

    /**
     * Cuts a type expression at the first top-level whitespace, so trailing
     * docblock text (asterisks, descriptions) is not treated as part of the type.
     */
    private function firstTypeToken(string $expression): string
    {
        $depth = 0;
        $token = '';

        foreach (str_split($expression) as $char) {
            if ($char === '<') {
                ++$depth;
            } elseif ($char === '>') {
                --$depth;
            } elseif ($depth === 0 && \in_array($char, [' ', "\t", "\r", "\n", '*'], true)) {
                break;
            }

            $token .= $char;
        }

        return $token;
    }

    /**
     * @param \ReflectionClass<object> $reflection
     *
     * @return array{0: ?string, 1: array<string, class-string>}
     */
    private function discriminator(\ReflectionClass $reflection): array
    {
        $attributes = $reflection->getAttributes(Discriminator::class);

        if ($attributes === []) {
            return [null, []];
        }

        $discriminator = $attributes[0]->newInstance();

        return [$discriminator->field, $discriminator->map];
    }

    /**
     * @param array<string, string> $docblockTypes
     * @param class-string $class
     */
    private function parameterMetadata(\ReflectionParameter $parameter, array $docblockTypes, string $namespace, string $class): ParameterMetadata
    {
        if ($parameter->isVariadic()) {
            throw new \LogicException(\sprintf(
                'Cannot map %s::%s — variadic constructor parameters are not supported.',
                $class,
                $parameter->getName(),
            ));
        }

        $jsonKey = $parameter->getName();
        $isExtras = false;
        $owner = \sprintf('parameter "%s" of %s', $parameter->getName(), $class);

        foreach ($parameter->getAttributes(Name::class) as $attribute) {
            $jsonKey = $attribute->newInstance()->key;
        }

        if ($parameter->getAttributes(Extras::class) !== []) {
            $isExtras = true;
        }

        $type = $this->typeOf($parameter->getType(), $docblockTypes[$parameter->getName()] ?? null, $namespace, $owner);

        foreach ($parameter->getAttributes(Format::class) as $attribute) {
            $type = $this->applyFormat($type, $attribute->newInstance()->format, $owner);
        }

        foreach ($parameter->getAttributes(Constraints::class) as $attribute) {
            $type = $this->applyConstraints($type, $attribute->newInstance(), $owner);
        }

        if ($isExtras && !($type instanceof MapType || $type instanceof MixedType)) {
            throw new \LogicException(\sprintf(
                'The #[Extras] parameter "%s" of %s must be an array.',
                $parameter->getName(),
                $class,
            ));
        }

        return new ParameterMetadata(
            $parameter->getName(),
            $jsonKey,
            $type,
            $parameter->isDefaultValueAvailable(),
            $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
            $isExtras,
        );
    }

    /**
     * Attaches a #[Format] to the member's type node, descending through a
     * nullable wrapper. Formats apply to string and date-time members only,
     * and only formats the engine can actually validate are accepted.
     *
     * @param string $owner member description used in configuration-error messages
     */
    private function applyFormat(TypeNode $type, string $format, string $owner): TypeNode
    {
        $kind = FormatKind::tryFrom($format);

        if ($kind === null) {
            throw new \LogicException(\sprintf(
                'Unknown format "%s" on %s — supported formats: %s.',
                $format,
                $owner,
                implode(', ', array_map(static fn(FormatKind $case): string => $case->value, FormatKind::cases())),
            ));
        }

        if ($type instanceof NullableType) {
            return new NullableType($this->applyFormat($type->inner, $format, $owner));
        }

        if ($type instanceof DateTimeType && ($kind === FormatKind::DateTime || $kind === FormatKind::Date)) {
            return new DateTimeType($kind);
        }

        if ($type instanceof ScalarType && $type->kind === ScalarKind::String) {
            return new ScalarType(ScalarKind::String, $kind);
        }

        throw new \LogicException(\sprintf(
            '#[Format(\'%s\')] does not apply to %s — formats apply to string members, and date-time/date to \DateTimeImmutable members.',
            $format,
            $owner,
        ));
    }

    private const array STRING_CONSTRAINTS = ['minLength', 'maxLength', 'pattern'];
    private const array NUMBER_CONSTRAINTS = ['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf'];
    private const array LIST_CONSTRAINTS = ['minItems', 'maxItems', 'uniqueItems'];
    private const array MAP_CONSTRAINTS = ['minProperties', 'maxProperties'];

    /**
     * Attaches a #[Constraints] to the member's type node, descending through
     * a nullable wrapper. Each keyword group applies to one member kind only
     * (strings, numbers, lists, maps) — a keyword on any other kind, like a
     * self-contradictory declaration, is a configuration error.
     *
     * @param string $owner member description used in configuration-error messages
     */
    private function applyConstraints(TypeNode $type, Constraints $constraints, string $owner): TypeNode
    {
        if ($type instanceof NullableType) {
            return new NullableType($this->applyConstraints($type->inner, $constraints, $owner));
        }

        $declared = array_keys(array_filter(
            get_object_vars($constraints),
            static fn(mixed $keyword): bool => $keyword !== null,
        ));

        if ($declared === []) {
            throw new \LogicException(\sprintf(
                '#[Constraints] on %s declares no keyword — remove the attribute or declare a constraint.',
                $owner,
            ));
        }

        $this->assertConstraintsAreSane($constraints, $owner);

        $set = new ConstraintSet(
            minLength: $constraints->minLength,
            maxLength: $constraints->maxLength,
            pattern: $constraints->pattern,
            minimum: $constraints->minimum,
            maximum: $constraints->maximum,
            exclusiveMinimum: $constraints->exclusiveMinimum,
            exclusiveMaximum: $constraints->exclusiveMaximum,
            multipleOf: $constraints->multipleOf,
            minItems: $constraints->minItems,
            maxItems: $constraints->maxItems,
            uniqueItems: $constraints->uniqueItems,
            minProperties: $constraints->minProperties,
            maxProperties: $constraints->maxProperties,
        );

        if ($type instanceof ScalarType && $type->kind === ScalarKind::String) {
            $this->assertConstraintGroup($declared, self::STRING_CONSTRAINTS, $owner);

            return new ScalarType($type->kind, $type->format, $set);
        }

        if ($type instanceof ScalarType && ($type->kind === ScalarKind::Integer || $type->kind === ScalarKind::Float)) {
            $this->assertConstraintGroup($declared, self::NUMBER_CONSTRAINTS, $owner);

            return new ScalarType($type->kind, $type->format, $set);
        }

        if ($type instanceof ListType) {
            $this->assertConstraintGroup($declared, self::LIST_CONSTRAINTS, $owner);

            return new ListType($type->item, $set);
        }

        if ($type instanceof MapType) {
            $this->assertConstraintGroup($declared, self::MAP_CONSTRAINTS, $owner);

            return new MapType($type->value, $set);
        }

        throw new \LogicException(\sprintf(
            '#[Constraints] does not apply to %s — constraints apply to string, int/float, list and map members.',
            $owner,
        ));
    }

    /**
     * @param list<string> $declared
     * @param list<string> $allowed
     */
    private function assertConstraintGroup(array $declared, array $allowed, string $owner): void
    {
        $stray = array_diff($declared, $allowed);

        if ($stray !== []) {
            throw new \LogicException(\sprintf(
                'Constraint keyword(s) %s do not apply to %s — allowed on this member kind: %s.',
                implode(', ', $stray),
                $owner,
                implode(', ', $allowed),
            ));
        }
    }

    /**
     * Rejects declarations no value could ever satisfy (or that make no
     * sense) — negative lengths/counts, a minimum above its maximum, a
     * non-positive multipleOf, a pattern that does not compile.
     */
    private function assertConstraintsAreSane(Constraints $constraints, string $owner): void
    {
        foreach (['minLength', 'maxLength', 'minItems', 'maxItems', 'minProperties', 'maxProperties'] as $count) {
            if ($constraints->{$count} !== null && $constraints->{$count} < 0) {
                throw new \LogicException(\sprintf('%s on %s must be >= 0, got %d.', $count, $owner, $constraints->{$count}));
            }
        }

        $pairs = [
            ['minLength', 'maxLength'],
            ['minimum', 'maximum'],
            ['minItems', 'maxItems'],
            ['minProperties', 'maxProperties'],
        ];

        foreach ($pairs as [$minimum, $maximum]) {
            if ($constraints->{$minimum} !== null && $constraints->{$maximum} !== null && $constraints->{$minimum} > $constraints->{$maximum}) {
                throw new \LogicException(\sprintf(
                    '%s (%s) exceeds %s (%s) on %s — no value could satisfy both.',
                    $minimum,
                    $constraints->{$minimum},
                    $maximum,
                    $constraints->{$maximum},
                    $owner,
                ));
            }
        }

        if ($constraints->exclusiveMinimum !== null && $constraints->exclusiveMaximum !== null && $constraints->exclusiveMinimum >= $constraints->exclusiveMaximum) {
            throw new \LogicException(\sprintf(
                'exclusiveMinimum (%s) must be below exclusiveMaximum (%s) on %s — no value could satisfy both.',
                $constraints->exclusiveMinimum,
                $constraints->exclusiveMaximum,
                $owner,
            ));
        }

        if ($constraints->multipleOf !== null && $constraints->multipleOf <= 0) {
            throw new \LogicException(\sprintf('multipleOf on %s must be > 0, got %s.', $owner, $constraints->multipleOf));
        }

        if ($constraints->pattern !== null && @preg_match(ConstraintSet::delimit($constraints->pattern), '') === false) {
            throw new \LogicException(\sprintf('The pattern "%s" on %s does not compile.', $constraints->pattern, $owner));
        }
    }

    /**
     * Combines a native reflection type with an optional docblock refinement.
     *
     * @param string $owner member description used in configuration-error messages
     */
    private function typeOf(?\ReflectionType $native, ?string $docblockType, string $namespace, string $owner): TypeNode
    {
        if ($docblockType !== null) {
            $parsed = $this->parser->parse($docblockType, $namespace);

            // A redundant Nullable(Nullable(T)) wrap would be behaviorally
            // identical, so no instanceof guard is needed.
            return $native?->allowsNull() === true ? new NullableType($parsed) : $parsed;
        }

        if ($native === null) {
            return new MixedType();
        }

        if (!$native instanceof \ReflectionNamedType) {
            throw new \LogicException(\sprintf(
                'Cannot map %s — union/intersection native types are not supported (use ?T for nullable, class hierarchies for discriminated unions).',
                $owner,
            ));
        }

        $parsed = $this->parser->parse($native->getName(), $namespace);

        return $native->allowsNull() && !$parsed instanceof MixedType ? new NullableType($parsed) : $parsed;
    }

    /**
     * Extracts `@param <type> $<name>` declarations from the constructor docblock.
     *
     * @return array<string, string> parameter name → type string
     */
    private function docblockParameterTypes(\ReflectionMethod $constructor): array
    {
        $docblock = $constructor->getDocComment();

        if ($docblock === false) {
            return [];
        }

        preg_match_all('/@param\s+(.+?)\s+\$(\w+)/', $docblock, $matches, \PREG_SET_ORDER);

        $types = [];

        foreach ($matches as $match) {
            $types[$match[2]] = $match[1];
        }

        return $types;
    }
}
