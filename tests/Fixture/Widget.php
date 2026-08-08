<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

use JsonMine\Attribute\Discriminator;

/**
 * A union root with an empty closed map — variants come from plugins only.
 */
#[Discriminator('kind')]
abstract readonly class Widget {}
