<?php

declare(strict_types=1);

namespace Ingot\Attribute;

/**
 * Declares simple value constraints on a member, named and behaving exactly
 * like the JSON Schema draft 2020-12 validation keywords, so the mapping
 * engine and a generated schema always agree.
 *
 * Each keyword group applies to one member kind only — strings take
 * minLength/maxLength/pattern, int/float take the numeric bounds and
 * multipleOf, lists take minItems/maxItems/uniqueItems, maps take
 * minProperties/maxProperties. A keyword on any other member kind, an empty
 * attribute, or a self-contradictory declaration (min above max,
 * non-positive multipleOf, a pattern that does not compile) is a
 * configuration error. A violating value is a data error (codes
 * "mapping.min_length", "mapping.pattern", …). On nullable members the
 * constraints describe the non-null branch — null passes. The pattern is
 * written without delimiters and matched unanchored (anchor with ^/$), in
 * the PCRE ∩ ECMA-262 common subset; SchemaGenerator copies every declared
 * keyword into the generated schema verbatim.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Constraints
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
}
