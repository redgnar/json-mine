<?php

declare(strict_types=1);

namespace Ingot;

use Ingot\Mapping\Mapper;
use Ingot\Mapping\Metadata\MetadataFactory;
use Ingot\Mapping\Type\TypeParser;
use Ingot\Mapping\ValidatorRegistry;
use Ingot\Mapping\VariantRegistry;
use Ingot\Schema\InMemorySchemaVault;
use Ingot\Schema\OpisSchemaValidator;
use Ingot\Schema\Schema;
use Ingot\Schema\SchemaValidator;
use Ingot\Schema\SchemaVault;
use Ingot\Validation\ObjectValidator;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Configures and builds a {@see TreeMapper}. Immutable and fluent — each
 * with*() returns a new builder. Build once (bootstrap/DI), map many times.
 */
final class MapperBuilder
{
    private ?SchemaValidator $schemaValidator = null;

    private ?CacheItemPoolInterface $cachePool = null;

    private ?SchemaVault $externalVault = null;

    /** @var array<class-string, Schema|\Closure(mixed): ?Schema> */
    private array $schemas = [];

    /** @var list<\Closure(class-string, mixed): ?Schema> */
    private array $schemaResolvers = [];

    private Coercion $coercion = Coercion::Strict;

    /** @var list<array{class-string, ObjectValidator<object>|\Closure(): ObjectValidator<object>}> */
    private array $validators = [];

    /** @var list<array{class-string, string, class-string}> */
    private array $variants = [];

    /** @var array<class-string, class-string> */
    private array $variantFallbacks = [];

    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    /**
     * PSR-6 pool for cross-request caching of mapper metadata (e.g. a
     * symfony/cache adapter). Keys derive from class names only — clear the
     * pool when deploying changed classes.
     */
    public function withCache(CacheItemPoolInterface $pool): self
    {
        $clone = clone $this;
        $clone->cachePool = $pool;

        return $clone;
    }

    /**
     * Overrides the schema backend (default: {@see OpisSchemaValidator}).
     */
    public function withSchemaValidator(SchemaValidator $validator): self
    {
        $clone = clone $this;
        $clone->schemaValidator = $validator;

        return $clone;
    }

    /**
     * Binds a schema to a target class: mapping that class automatically
     * pre-checks the document. A Closure binding receives the decoded
     * document (the versioning hook).
     *
     * @param class-string $class
     * @param Schema|\Closure(mixed): ?Schema $schema
     */
    public function withSchema(string $class, Schema|\Closure $schema): self
    {
        $clone = clone $this;
        $clone->schemas[$class] = $schema;

        return $clone;
    }

    /**
     * Registers a dynamic resolver answering whether (and which) schema
     * applies to a class; `null` means "no schema for this class".
     *
     * @param \Closure(class-string, mixed): ?Schema $resolver
     */
    public function withSchemaResolver(\Closure $resolver): self
    {
        $clone = clone $this;
        $clone->schemaResolvers[] = $resolver;

        return $clone;
    }

    /**
     * Provides a vault consulted after explicit bindings and resolvers.
     */
    public function withSchemaVault(SchemaVault $vault): self
    {
        $clone = clone $this;
        $clone->externalVault = $vault;

        return $clone;
    }

    /**
     * Strict is the default; Lax enables the documented coercion table.
     */
    public function withCoercion(Coercion $coercion): self
    {
        $clone = clone $this;
        $clone->coercion = $coercion;

        return $clone;
    }

    /**
     * Binds a semantic validator to a target class (instanceof semantics).
     *
     * @template T of object
     *
     * @param class-string<T> $class
     * @param ObjectValidator<T> $validator
     */
    public function withValidator(string $class, ObjectValidator $validator): self
    {
        $clone = clone $this;
        // T is erased at the registry boundary: dispatch happens by instanceof at
        // runtime, so a validator only ever receives instances of its bound class.
        // That guarantee cannot be expressed to the type system.
        /** @var ObjectValidator<object> $validator @phpstan-ignore varTag.type */
        $clone->validators[] = [$class, $validator];

        return $clone;
    }

    /**
     * Same as withValidator(), resolved lazily on first use — for validators
     * with expensive dependencies (DI containers).
     *
     * @template T of object
     *
     * @param class-string<T> $class
     * @param \Closure(): ObjectValidator<T> $factory
     */
    public function withValidatorFactory(string $class, \Closure $factory): self
    {
        $clone = clone $this;
        // Same T-erasure as in withValidator() — see the comment there.
        /** @var \Closure(): ObjectValidator<object> $factory @phpstan-ignore varTag.type */
        $clone->validators[] = [$class, $factory];

        return $clone;
    }

    /**
     * Registers an open-union variant: mapping $base with the discriminator
     * equal to $value produces $variant. Merged with (and winning over) the
     * #[Discriminator] map on $base.
     *
     * @param class-string $base
     * @param class-string $variant
     */
    public function withVariant(string $base, string $value, string $variant): self
    {
        $clone = clone $this;
        $clone->variants[] = [$base, $value, $variant];

        return $clone;
    }

    /**
     * Unknown discriminator values hydrate $fallback instead of failing —
     * for editors and migrations that must pass unknown variants through.
     *
     * @param class-string $base
     * @param class-string $fallback
     */
    public function withVariantFallback(string $base, string $fallback): self
    {
        $clone = clone $this;
        $clone->variantFallbacks[$base] = $fallback;

        return $clone;
    }

    public function build(): TreeMapper
    {
        $vault = new InMemorySchemaVault();

        foreach ($this->schemas as $class => $schema) {
            $vault->bind($class, $schema);
        }

        foreach ($this->schemaResolvers as $resolver) {
            $vault->addResolver($resolver);
        }

        if ($this->externalVault !== null) {
            $external = $this->externalVault;
            $vault->addResolver(static fn(string $class, mixed $document): ?Schema => $external->resolve($class, $document));
        }

        $variants = new VariantRegistry();

        foreach ($this->variants as [$base, $value, $variant]) {
            $variants->register($base, $value, $variant);
        }

        foreach ($this->variantFallbacks as $base => $fallback) {
            $variants->registerFallback($base, $fallback);
        }

        $validators = new ValidatorRegistry();

        foreach ($this->validators as [$class, $validator]) {
            $validators->add($class, $validator);
        }

        return new Mapper(
            new TypeParser(),
            new MetadataFactory(new TypeParser(), $this->cachePool),
            $variants,
            $validators,
            $this->schemaValidator ?? new OpisSchemaValidator(),
            $vault,
            $this->coercion,
        );
    }
}
