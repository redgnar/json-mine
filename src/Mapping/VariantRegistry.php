<?php

declare(strict_types=1);

namespace Ingot\Mapping;

/**
 * Runtime registry for open discriminated unions: variants registered by
 * plugins at bootstrap plus an optional fallback class for unknown
 * discriminator values.
 */
final class VariantRegistry
{
    /** @var array<class-string, array<string, class-string>> */
    private array $variants = [];

    /** @var array<class-string, class-string> */
    private array $fallbacks = [];

    /**
     * @param class-string $base
     * @param class-string $variant
     */
    public function register(string $base, string $value, string $variant): void
    {
        $this->assertSubtype($base, $variant);

        $this->variants[$base][$value] = $variant;
    }

    /**
     * @param class-string $base
     * @param class-string $fallback
     */
    public function registerFallback(string $base, string $fallback): void
    {
        $this->assertSubtype($base, $fallback);

        $this->fallbacks[$base] = $fallback;
    }

    /**
     * A variant must be substitutable for its base — `map(Base::class, ...)`
     * promises to return a Base.
     *
     * @param class-string $base
     * @param class-string $variant
     */
    private function assertSubtype(string $base, string $variant): void
    {
        if (!is_a($variant, $base, true)) {
            throw new \LogicException(\sprintf('Variant %s must be a subtype of %s.', $variant, $base));
        }
    }

    /**
     * @param class-string $base
     *
     * @return array<string, class-string>
     */
    public function variantsFor(string $base): array
    {
        return $this->variants[$base] ?? [];
    }

    /**
     * @param class-string $base
     *
     * @return ?class-string
     */
    public function fallbackFor(string $base): ?string
    {
        return $this->fallbacks[$base] ?? null;
    }
}
