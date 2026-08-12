<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Discriminator;

/**
 * A union root with an empty closed map — variants come from plugins only.
 */
#[Discriminator('kind')]
abstract readonly class Widget {}
