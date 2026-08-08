<?php

declare(strict_types=1);

namespace JsonMine;

/**
 * Immutable JSON Pointer (RFC 6901).
 *
 * Used as the canonical error-path format across all validation surfaces
 * (schema, type mapping, semantic validators) and for tree navigation.
 */
final readonly class JsonPointer implements \Stringable
{
    /** @param list<string> $segments Decoded (unescaped) segments. */
    private function __construct(
        public array $segments,
    ) {}

    public static function root(): self
    {
        return new self([]);
    }

    /**
     * @throws \InvalidArgumentException when the pointer is non-empty and does not start with '/'
     */
    public static function fromString(string $pointer): self
    {
        if ($pointer === '') {
            return self::root();
        }

        if (!str_starts_with($pointer, '/')) {
            throw new \InvalidArgumentException(
                \sprintf('Invalid JSON Pointer "%s": a non-empty pointer must start with "/".', $pointer),
            );
        }

        $segments = array_map(
            // RFC 6901 order: '~1' → '/' first, then '~0' → '~'
            static fn(string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            explode('/', substr($pointer, 1)),
        );

        return new self($segments);
    }

    public function append(string|int $segment): self
    {
        return new self([...$this->segments, (string) $segment]);
    }

    /**
     * Resolves $other against this pointer (this acts as the base path).
     */
    public function join(self $other): self
    {
        return new self([...$this->segments, ...$other->segments]);
    }

    public function isRoot(): bool
    {
        return $this->segments === [];
    }

    public function toString(): string
    {
        if ($this->segments === []) {
            return '';
        }

        $encoded = array_map(
            // Encode order: '~' → '~0' first, then '/' → '~1'
            static fn(string $segment): string => str_replace(['~', '/'], ['~0', '~1'], $segment),
            $this->segments,
        );

        return '/' . implode('/', $encoded);
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->toString();
    }
}
