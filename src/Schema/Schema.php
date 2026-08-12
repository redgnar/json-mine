<?php

declare(strict_types=1);

namespace Ingot\Schema;

/**
 * A JSON Schema document.
 *
 * Per the specification a schema is either an object or a boolean
 * (`true` accepts everything, `false` rejects everything).
 */
final readonly class Schema
{
    private function __construct(
        public \stdClass|bool $document,
        public ?string $uri = null,
    ) {}

    public static function fromDocument(\stdClass|bool $document, ?string $uri = null): self
    {
        return new self($document, $uri);
    }

    /**
     * @throws \JsonException when $json is not valid JSON
     * @throws \InvalidArgumentException when the decoded value is not an object or a boolean
     */
    public static function fromJson(string $json, ?string $uri = null): self
    {
        $decoded = json_decode($json, false, flags: \JSON_THROW_ON_ERROR);

        if (!$decoded instanceof \stdClass && !\is_bool($decoded)) {
            throw new \InvalidArgumentException(
                \sprintf('A JSON Schema must be an object or a boolean, got %s.', get_debug_type($decoded)),
            );
        }

        return new self($decoded, $uri);
    }

    /**
     * @throws \RuntimeException when the file cannot be read
     * @throws \JsonException when the file content is not valid JSON
     * @throws \InvalidArgumentException when the decoded value is not an object or a boolean
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException(\sprintf('Schema file "%s" does not exist.', $path));
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException(\sprintf('Schema file "%s" cannot be read.', $path));
        }

        return self::fromJson($content, $path);
    }
}
