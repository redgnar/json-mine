<?php

declare(strict_types=1);

namespace Ingot\Examples\Workflow\Definition;

use Ingot\Attribute\Discriminator;

/**
 * A workflow node — the discriminated-union root of the definition model.
 * The union is fully open: unlike form fields, node types are plugin
 * territory, so the attribute declares only the discriminator field and
 * every variant is registered on the builder at bootstrap.
 */
#[Discriminator('type')]
abstract readonly class Node
{
    public function __construct(
        public string $id,
        public string $name = '',
    ) {}
}
