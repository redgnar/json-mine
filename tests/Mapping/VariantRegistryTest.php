<?php

declare(strict_types=1);

namespace JsonMine\Tests\Mapping;

use JsonMine\Mapping\VariantRegistry;
use JsonMine\Tests\Fixture\Address;
use JsonMine\Tests\Fixture\CustomField;
use JsonMine\Tests\Fixture\Field;
use JsonMine\Tests\Fixture\GenericField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VariantRegistry::class)]
final class VariantRegistryTest extends TestCase
{
    public function testRegistersAndExposesVariantsAndFallback(): void
    {
        // GIVEN
        $registry = new VariantRegistry();

        // WHEN
        $registry->register(Field::class, 'custom', CustomField::class);
        $registry->registerFallback(Field::class, GenericField::class);

        // THEN
        self::assertSame(['custom' => CustomField::class], $registry->variantsFor(Field::class));
        self::assertSame(GenericField::class, $registry->fallbackFor(Field::class));
        self::assertSame([], $registry->variantsFor(Address::class));
        self::assertNull($registry->fallbackFor(Address::class));
    }

    public function testRejectsVariantThatIsNotASubtypeOfTheBase(): void
    {
        // GIVEN
        $registry = new VariantRegistry();

        // THEN map(Base::class) promises to return a Base
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must be a subtype');

        // WHEN
        $registry->register(Field::class, 'address', Address::class);
    }

    public function testRejectsFallbackThatIsNotASubtypeOfTheBase(): void
    {
        // GIVEN
        $registry = new VariantRegistry();

        // THEN
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must be a subtype');

        // WHEN
        $registry->registerFallback(Field::class, Address::class);
    }
}
