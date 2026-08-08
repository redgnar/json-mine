<?php

declare(strict_types=1);

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

return static function (Configuration $config): Configuration {
    return $config
        // Declared for the upcoming metadata/schema cache (see .claude/plan/04-core-api-sketch.md);
        // remove this filter as soon as src/ starts consuming the package.
        ->addNamedFilter(NamedFilter::fromString('psr/cache'));
};
