<?php

declare(strict_types=1);

namespace Ingot;

use Ingot\Error\MappingFailed;

/**
 * Maps a decoded JSON document onto a typed PHP structure in one pass:
 * schema pre-check (when a schema is bound) → type mapping → semantic
 * validators. All failures are aggregated into one report.
 *
 * $target is a class name or a type string (e.g. 'list<Field>', 'int',
 * 'array<string, Node>') — docblock-level types are honored at runtime.
 */
interface TreeMapper
{
    /**
     * @template T of object
     *
     * @param class-string<T>|string $target
     *
     * @return ($target is class-string<T> ? T : mixed)
     *
     * @throws MappingFailed when the document cannot be mapped
     */
    public function map(string $target, Source $source): mixed;

    /**
     * Non-throwing variant: data problems land in the result, never as exceptions.
     *
     * @template T of object
     *
     * @param class-string<T>|string $target
     *
     * @return ($target is class-string<T> ? MappingResult<T> : MappingResult<mixed>)
     */
    public function tryMap(string $target, Source $source): MappingResult;

    /**
     * The reverse direction: mapped values → json_encode-ready data. Reads the
     * same metadata as hydration; #[Extras] merges back flat and discriminated
     * union variants re-emit their discriminator, so load → edit → save
     * round-trips losslessly.
     *
     * @throws \LogicException when the value is not normalizable (resources, object cycles)
     */
    public function normalize(mixed $value): mixed;
}
