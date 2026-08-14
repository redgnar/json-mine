<?php

declare(strict_types=1);

namespace Ingot\Mapping\Type;

/**
 * Value constraints attached to a type node from #[Constraints]. Only the
 * keyword group matching the node's kind is ever set — MetadataFactory
 * enforces the routing and the declaration's sanity. Names and semantics
 * follow the JSON Schema draft 2020-12 validation vocabulary, so
 * SchemaGenerator emits the keywords verbatim.
 */
final readonly class ConstraintSet
{
    public function __construct(
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $pattern = null,
        public int|float|null $minimum = null,
        public int|float|null $maximum = null,
        public int|float|null $exclusiveMinimum = null,
        public int|float|null $exclusiveMaximum = null,
        public int|float|null $multipleOf = null,
        public ?int $minItems = null,
        public ?int $maxItems = null,
        public ?bool $uniqueItems = null,
        public ?int $minProperties = null,
        public ?int $maxProperties = null,
    ) {}

    public function matchesPattern(string $value): bool
    {
        return $this->pattern !== null && preg_match(self::delimit($this->pattern), $value) === 1;
    }

    /**
     * Wraps a delimiter-less JSON Schema pattern for preg_match(). '~' is not
     * special in ECMA-262 or PCRE, so escaping it never changes what matches.
     */
    public static function delimit(string $pattern): string
    {
        return '~' . str_replace('~', '\~', $pattern) . '~u';
    }

    /**
     * The declared keywords under their JSON Schema names, for SchemaGenerator.
     *
     * @return array<string, int|float|string|bool>
     */
    public function toKeywords(): array
    {
        $keywords = [
            'minLength' => $this->minLength,
            'maxLength' => $this->maxLength,
            'pattern' => $this->pattern,
            'minimum' => $this->minimum,
            'maximum' => $this->maximum,
            'exclusiveMinimum' => $this->exclusiveMinimum,
            'exclusiveMaximum' => $this->exclusiveMaximum,
            'multipleOf' => $this->multipleOf,
            'minItems' => $this->minItems,
            'maxItems' => $this->maxItems,
            'uniqueItems' => $this->uniqueItems,
            'minProperties' => $this->minProperties,
            'maxProperties' => $this->maxProperties,
        ];

        return array_filter($keywords, static fn(int|float|string|bool|null $keyword): bool => $keyword !== null);
    }
}
