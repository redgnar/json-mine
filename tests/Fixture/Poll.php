<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Attribute\Constraints;

final readonly class Poll
{
    /**
     * @param list<string> $options
     * @param array<string, int> $votes
     */
    public function __construct(
        #[Constraints(minItems: 2, maxItems: 4, uniqueItems: true)]
        public array $options,
        #[Constraints(minProperties: 1, maxProperties: 3)]
        public array $votes = [],
        #[Constraints(minLength: 1, maxLength: 2)]
        public ?string $note = null,
    ) {}
}
