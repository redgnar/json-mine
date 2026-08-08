<?php

declare(strict_types=1);

namespace JsonMine\Mapping\Type;

/**
 * Parses type strings (native type names, PHPDoc generics) into a TypeNode tree.
 *
 * Supported grammar (MVP):
 *   ?T, T|null, null|T          — nullable
 *   int, float, string, bool    — scalars (+ integer/double/boolean aliases)
 *   mixed, array                — pass-through / untyped map
 *   list<T>, non-empty-list<T>  — lists
 *   array<V>, array<K, V>       — maps (key type accepted, not enforced)
 *   backed enums, \DateTimeImmutable / \DateTimeInterface, any class/interface
 *
 * Class names in docblocks may be relative to the declaring class — pass its
 * namespace as $namespace to enable resolution.
 *
 * Failures throw \InvalidArgumentException: an unparsable type is a
 * configuration (programmer) error, never a data error.
 */
final class TypeParser
{
    public function parse(string $type, ?string $namespace = null): TypeNode
    {
        $type = trim($type);

        if ($type === '') {
            throw new \InvalidArgumentException('Cannot parse an empty type.');
        }

        if (str_starts_with($type, '?')) {
            return $this->nullable($this->parse(substr($type, 1), $namespace));
        }

        $unionParts = $this->splitTopLevel($type, '|');

        if (\count($unionParts) > 1) {
            return $this->parseUnion($unionParts, $type, $namespace);
        }

        switch (strtolower($type)) {
            case 'int':
            case 'integer':
                return new ScalarType(ScalarKind::Integer);
            case 'float':
            case 'double':
                return new ScalarType(ScalarKind::Float);
            case 'string':
                return new ScalarType(ScalarKind::String);
            case 'bool':
            case 'boolean':
                return new ScalarType(ScalarKind::Boolean);
            case 'mixed':
                return new MixedType();
            case 'array':
                return new MapType(new MixedType());
        }

        if (preg_match('/^(?:list|non-empty-list)<(.+)>$/s', $type, $matches) === 1) {
            return new ListType($this->parse($matches[1], $namespace));
        }

        if (preg_match('/^(?:array|non-empty-array)<(.+)>$/s', $type, $matches) === 1) {
            return $this->parseMap($matches[1], $namespace);
        }

        return $this->parseClassLike($type, $namespace);
    }

    /**
     * @param list<string> $parts pre-trimmed by splitTopLevel()
     */
    private function parseUnion(array $parts, string $original, ?string $namespace): TypeNode
    {
        $withoutNull = array_values(array_filter($parts, static fn(string $part): bool => strtolower($part) !== 'null'));

        if (\count($withoutNull) === 1 && \count($parts) === 2) {
            return $this->nullable($this->parse($withoutNull[0], $namespace));
        }

        throw new \InvalidArgumentException(
            \sprintf('Unsupported union type "%s": only "T|null" unions are supported (discriminated unions are expressed as class hierarchies).', $original),
        );
    }

    private function parseMap(string $inner, ?string $namespace): TypeNode
    {
        $parts = $this->splitTopLevel($inner, ',');

        if (\count($parts) === 1) {
            return new MapType($this->parse($parts[0], $namespace));
        }

        if (\count($parts) === 2) {
            $key = strtolower($parts[0]);

            if (!\in_array($key, ['int', 'string', 'array-key'], true)) {
                throw new \InvalidArgumentException(\sprintf('Unsupported array key type "%s".', $parts[0]));
            }

            return new MapType($this->parse($parts[1], $namespace));
        }

        throw new \InvalidArgumentException(\sprintf('Cannot parse array type "array<%s>".', $inner));
    }

    private function parseClassLike(string $type, ?string $namespace): TypeNode
    {
        $class = ltrim($type, '\\');

        // class_exists() also covers enums (an enum is a class), so interfaces
        // are the only extra case to check.
        if (!class_exists($class) && !interface_exists($class)) {
            $qualified = $namespace !== null ? $namespace . '\\' . $class : null;

            if ($qualified === null || (!class_exists($qualified) && !interface_exists($qualified))) {
                throw new \InvalidArgumentException(\sprintf('Cannot parse type "%s": unknown type or class.', $type));
            }

            $class = $qualified;
        }

        if (enum_exists($class)) {
            if (!is_subclass_of($class, \BackedEnum::class)) {
                throw new \InvalidArgumentException(
                    \sprintf('Enum "%s" is not backed — only backed enums can be mapped from JSON.', $class),
                );
            }

            return new EnumType($class);
        }

        if ($class === \DateTimeImmutable::class || $class === \DateTimeInterface::class) {
            return new DateTimeType();
        }

        /** @var class-string $class */
        return new ClassType($class);
    }

    private function nullable(TypeNode $inner): TypeNode
    {
        // A redundant Nullable(Nullable(T)) / Nullable(Mixed) wrap would be
        // behaviorally identical, so no guard is needed here.
        return new NullableType($inner);
    }

    /**
     * Splits on $separator, ignoring occurrences nested inside angle brackets.
     *
     * @return list<string>
     */
    private function splitTopLevel(string $type, string $separator): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($type) as $char) {
            if ($char === '<') {
                ++$depth;
            } elseif ($char === '>') {
                --$depth;
            }

            if ($char === $separator && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $parts[] = trim($current);

        return $parts;
    }
}
