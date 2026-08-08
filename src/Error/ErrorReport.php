<?php

declare(strict_types=1);

namespace JsonMine\Error;

/**
 * Immutable, ordered collection of mapping errors.
 *
 * An empty report means "valid".
 *
 * @implements \IteratorAggregate<int, MappingError>
 */
final readonly class ErrorReport implements \Countable, \IteratorAggregate
{
    /** @param list<MappingError> $errors */
    private function __construct(
        public array $errors,
    ) {}

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(MappingError ...$errors): self
    {
        return new self(array_values($errors));
    }

    public function merge(self $other): self
    {
        return new self([...$this->errors, ...$other->errors]);
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    public function count(): int
    {
        return \count($this->errors);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->errors);
    }
}
