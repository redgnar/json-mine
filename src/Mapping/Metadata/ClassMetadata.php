<?php

declare(strict_types=1);

namespace JsonMine\Mapping\Metadata;

/**
 * How a class hydrates from a JSON object.
 */
final readonly class ClassMetadata
{
    public function __construct(
        /** @var class-string */
        public string $class,
        /** @var list<ParameterMetadata> */
        public array $parameters,
        /** @var list<PropertyMetadata> members not covered by the constructor, set after construction */
        public array $properties,
        public bool $isInstantiable,
        /** Set when this class is a discriminated-union root (#[Discriminator]). */
        public ?string $discriminatorField,
        /** @var array<string, class-string> Closed-union variants declared on the root. */
        public array $discriminatorMap,
    ) {}

    public function extrasParameter(): ?ParameterMetadata
    {
        foreach ($this->parameters as $parameter) {
            if ($parameter->isExtras) {
                return $parameter;
            }
        }

        return null;
    }

    public function extrasProperty(): ?PropertyMetadata
    {
        foreach ($this->properties as $property) {
            if ($property->isExtras) {
                return $property;
            }
        }

        return null;
    }
}
