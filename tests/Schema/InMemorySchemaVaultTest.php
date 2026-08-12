<?php

declare(strict_types=1);

namespace Ingot\Tests\Schema;

use Ingot\Schema\InMemorySchemaVault;
use Ingot\Schema\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemorySchemaVault::class)]
final class InMemorySchemaVaultTest extends TestCase
{
    public function testResolvesNullForUnknownClass(): void
    {
        // GIVEN
        $vault = new InMemorySchemaVault();

        // WHEN
        $schema = $vault->resolve(\stdClass::class, null);

        // THEN mapping proceeds without a schema pre-check
        self::assertFalse($vault->has(\stdClass::class));
        self::assertNull($schema);
    }

    public function testResolvesAnExplicitlyBoundSchema(): void
    {
        // GIVEN
        $bound = Schema::fromDocument(true);
        $vault = new InMemorySchemaVault()->bind(\stdClass::class, $bound);

        // WHEN
        $resolved = $vault->resolve(\stdClass::class, null);

        // THEN
        self::assertTrue($vault->has(\stdClass::class));
        self::assertSame($bound, $resolved);
    }

    public function testCallableBindingReceivesTheDocumentForVersioning(): void
    {
        // GIVEN
        $v1 = Schema::fromJson('{"title": "v1"}');
        $v2 = Schema::fromJson('{"title": "v2"}');
        $vault = new InMemorySchemaVault()->bind(
            \stdClass::class,
            static fn(mixed $document): Schema => $document instanceof \stdClass && ($document->version ?? null) === 2 ? $v2 : $v1,
        );

        // WHEN
        $resolved = $vault->resolve(\stdClass::class, json_decode('{"version": 2}'));

        // THEN
        self::assertSame($v2, $resolved);
    }

    public function testConsultsResolversInOrderAndFirstNonNullWins(): void
    {
        // GIVEN
        $first = Schema::fromJson('{"title": "first"}');
        $second = Schema::fromJson('{"title": "second"}');
        $vault = new InMemorySchemaVault()
            ->addResolver(static fn(string $class, mixed $document): ?Schema => null)
            ->addResolver(static fn(string $class, mixed $document): Schema => $first)
            ->addResolver(static fn(string $class, mixed $document): Schema => $second);

        // WHEN
        $resolved = $vault->resolve(\stdClass::class, null);

        // THEN
        self::assertSame($first, $resolved);
    }

    public function testExplicitBindingWinsOverResolvers(): void
    {
        // GIVEN
        $bound = Schema::fromJson('{"title": "bound"}');
        $fromResolver = Schema::fromJson('{"title": "resolver"}');
        $vault = new InMemorySchemaVault()
            ->addResolver(static fn(string $class, mixed $document): Schema => $fromResolver)
            ->bind(\stdClass::class, $bound);

        // WHEN
        $resolved = $vault->resolve(\stdClass::class, null);

        // THEN
        self::assertSame($bound, $resolved);
    }

    public function testResolverReceivesTheClassName(): void
    {
        // GIVEN a convention-based resolver
        $schema = Schema::fromJson('{"title": "stdClass"}');
        $vault = new InMemorySchemaVault()->addResolver(
            static fn(string $class, mixed $document): ?Schema => $class === \stdClass::class ? $schema : null,
        );

        // WHEN / THEN
        self::assertSame($schema, $vault->resolve(\stdClass::class, null));
    }
}
