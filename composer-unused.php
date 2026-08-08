<?php

declare(strict_types=1);

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

return static function (Configuration $config): Configuration {
    return $config
        // Declared for the upcoming Schema/Cache modules (see .claude/plan/04-core-api-sketch.md);
        // remove these filters as soon as src/ starts consuming the packages.
        ->addNamedFilter(NamedFilter::fromString('opis/json-schema'))
        ->addNamedFilter(NamedFilter::fromString('psr/cache'));
};
