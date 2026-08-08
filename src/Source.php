<?php

declare(strict_types=1);

namespace JsonMine;

use JsonMine\Schema\Schema;

/**
 * Input for a mapping operation.
 *
 * Owns the JSON parse step: malformed input surfaces when the mapper reads
 * {@see data()}, so parse errors join the same aggregated error report as
 * every other failure. Decoding produces objects as \stdClass (never
 * associative arrays) to preserve the JSON object-vs-array distinction.
 */
final readonly class Source
{
    private function __construct(
        private ?string $rawJson,
        private mixed $decoded,
        /** Per-call schema override; wins over the mapper's SchemaVault. */
        public ?Schema $schemaOverride = null,
    ) {}

    public static function json(string $json): self
    {
        return new self($json, null);
    }

    /**
     * Already-decoded input (e.g. handed over by a framework).
     *
     * @param array<array-key, mixed>|\stdClass $data
     */
    public static function array(array|\stdClass $data): self
    {
        return new self(null, $data);
    }

    /**
     * @throws \RuntimeException when the file cannot be read
     */
    public static function file(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException(\sprintf('Source file "%s" does not exist.', $path));
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException(\sprintf('Source file "%s" cannot be read.', $path));
        }

        return new self($content, null);
    }

    public function withSchema(Schema $schema): self
    {
        return new self($this->rawJson, $this->decoded, $schema);
    }

    /**
     * The decoded document. Called once per mapping operation.
     *
     * @throws \JsonException when the raw input is not valid JSON
     */
    public function data(): mixed
    {
        if ($this->rawJson === null) {
            return $this->decoded;
        }

        return json_decode($this->rawJson, false, flags: \JSON_THROW_ON_ERROR);
    }
}
