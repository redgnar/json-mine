<?php

declare(strict_types=1);

namespace Ingot\Tree;

use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\Error\MappingFailed;
use Ingot\JsonPointer;
use Ingot\Source;

/**
 * Typed access to a decoded JSON document without mapping classes — for
 * documents whose shape exists only at runtime (configurator-built forms,
 * plugin-defined node parameters).
 *
 * Navigation walks JSON Pointers; typed accessors assert the actual JSON
 * type. Every failure throws {@see MappingFailed} carrying the absolute
 * pointer — the same error format as the mapper.
 */
final readonly class JsonNode
{
    private function __construct(
        private mixed $value,
        private JsonPointer $path,
    ) {}

    /**
     * @throws MappingFailed when the source is not valid JSON
     */
    public static function of(Source $source): self
    {
        try {
            $data = $source->data();
        } catch (\JsonException $exception) {
            throw new MappingFailed(ErrorReport::of(
                new MappingError(JsonPointer::root(), 'source.malformed_json', $exception->getMessage()),
            ));
        }

        return new self($data, JsonPointer::root());
    }

    public function path(): JsonPointer
    {
        return $this->path;
    }

    /**
     * The raw decoded value (objects as \stdClass).
     */
    public function raw(): mixed
    {
        return $this->value;
    }

    public function exists(string $pointer): bool
    {
        return $this->find(JsonPointer::fromString($pointer)) !== Missing::Value;
    }

    /**
     * Navigates a JSON Pointer relative to this node.
     *
     * @throws MappingFailed when the pointer does not resolve
     */
    public function get(string $pointer): self
    {
        $relative = JsonPointer::fromString($pointer);
        $found = $this->find($relative);

        if ($found === Missing::Value) {
            throw $this->error($this->path->join($relative), 'tree.missing_node', 'No value at this location.');
        }

        return new self($found, $this->path->join($relative));
    }

    public function isNull(): bool
    {
        return $this->value === null;
    }

    public function string(): string
    {
        return \is_string($this->value) ? $this->value : throw $this->typeError('string');
    }

    public function int(): int
    {
        return \is_int($this->value) ? $this->value : throw $this->typeError('int');
    }

    /**
     * JSON has no int/float distinction for whole numbers — both are accepted
     * (the declared return type widens int to float).
     */
    public function float(): float
    {
        return \is_float($this->value) || \is_int($this->value) ? $this->value : throw $this->typeError('float');
    }

    public function bool(): bool
    {
        return \is_bool($this->value) ? $this->value : throw $this->typeError('bool');
    }

    /**
     * @throws MappingFailed when the value is not a parsable date-time string
     */
    public function dateTime(): \DateTimeImmutable
    {
        if (!\is_string($this->value)) {
            throw $this->typeError('date-time string');
        }

        try {
            return new \DateTimeImmutable($this->value);
        } catch (\Exception) {
            throw $this->error($this->path, 'mapping.format', \sprintf('"%s" is not a valid date-time.', $this->value));
        }
    }

    /**
     * The node as a JSON-array of child nodes.
     *
     * @return list<self>
     *
     * @throws MappingFailed when the value is not a JSON array
     */
    public function list(): array
    {
        if (!\is_array($this->value) || !array_is_list($this->value)) {
            throw $this->typeError('JSON array');
        }

        $nodes = [];

        foreach ($this->value as $index => $item) {
            $nodes[] = new self($item, $this->path->append($index));
        }

        return $nodes;
    }

    /**
     * The node as a JSON-object of child nodes, keyed by member name
     * (PHP coerces numeric-string member names to int keys).
     *
     * @return array<array-key, self>
     *
     * @throws MappingFailed when the value is not a JSON object
     */
    public function map(): array
    {
        if (!$this->value instanceof \stdClass) {
            throw $this->typeError('JSON object');
        }

        $nodes = [];

        foreach (get_object_vars($this->value) as $key => $item) {
            $nodes[$key] = new self($item, $this->path->append($key));
        }

        return $nodes;
    }

    private function find(JsonPointer $pointer): mixed
    {
        $current = $this->value;

        foreach ($pointer->segments as $segment) {
            if ($current instanceof \stdClass) {
                if (!property_exists($current, $segment)) {
                    return Missing::Value;
                }

                $current = $current->{$segment};

                continue;
            }

            // array_key_exists() coerces exactly the canonical numeric strings
            // ("2" matches index 2, "1x"/"01" match nothing) — no regex needed.
            if (\is_array($current) && \array_key_exists($segment, $current)) {
                $current = $current[$segment];

                continue;
            }

            return Missing::Value;
        }

        return $current;
    }

    private function typeError(string $expected): MappingFailed
    {
        return $this->error(
            $this->path,
            'mapping.type',
            \sprintf('Expected %s, got %s.', $expected, get_debug_type($this->value)),
        );
    }

    private function error(JsonPointer $pointer, string $code, string $message): MappingFailed
    {
        return new MappingFailed(ErrorReport::of(new MappingError($pointer, $code, $message, $this->value)));
    }
}
