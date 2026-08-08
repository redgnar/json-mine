<?php

declare(strict_types=1);

namespace JsonMine\Mapping\Metadata;

use JsonMine\Attribute\Discriminator;
use JsonMine\Attribute\Extras;
use JsonMine\Attribute\Name;
use JsonMine\Mapping\Type\MapType;
use JsonMine\Mapping\Type\MixedType;
use JsonMine\Mapping\Type\NullableType;
use JsonMine\Mapping\Type\TypeNode;
use JsonMine\Mapping\Type\TypeParser;

/**
 * Builds (and caches in memory) hydration metadata from reflection:
 * constructor parameters, native types refined by docblock `@param` types,
 * and the mapping attributes.
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
    ) {}

    /**
     * @param class-string $class
     */
    public function for(string $class): ClassMetadata
    {
        return $this->cache[$class] ??= $this->build($class);
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

        if ($reflection->isInstantiable() && $constructor !== null) {
            $docblockTypes = $this->docblockParameterTypes($constructor);
            $namespace = $reflection->getNamespaceName();

            foreach ($constructor->getParameters() as $parameter) {
                $parameters[] = $this->parameterMetadata($parameter, $docblockTypes, $namespace, $class);
            }
        }

        return new ClassMetadata(
            $class,
            $parameters,
            $reflection->isInstantiable(),
            $discriminatorField,
            $discriminatorMap,
        );
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

        foreach ($parameter->getAttributes(Name::class) as $attribute) {
            $jsonKey = $attribute->newInstance()->key;
        }

        if ($parameter->getAttributes(Extras::class) !== []) {
            $isExtras = true;
        }

        $type = $this->resolveType($parameter, $docblockTypes[$parameter->getName()] ?? null, $namespace, $class);

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
     * @param class-string $class
     */
    private function resolveType(\ReflectionParameter $parameter, ?string $docblockType, string $namespace, string $class): TypeNode
    {
        $native = $parameter->getType();

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
                'Cannot map parameter "%s" of %s — union/intersection native types are not supported (use ?T for nullable, class hierarchies for discriminated unions).',
                $parameter->getName(),
                $class,
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
