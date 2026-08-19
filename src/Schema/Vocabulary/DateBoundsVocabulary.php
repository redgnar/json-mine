<?php

declare(strict_types=1);

namespace Ingot\Schema\Vocabulary;

use Opis\JsonSchema\Parsers\Vocabulary;

/**
 * The keywords this library adds to the drafts opis implements: the two ends
 * of a date range, spelled the way ajv-formats spells them.
 */
final class DateBoundsVocabulary extends Vocabulary
{
    public function __construct()
    {
        parent::__construct([
            new DateBoundKeywordParser('formatMinimum', isMinimum: true),
            new DateBoundKeywordParser('formatMaximum', isMinimum: false),
        ]);
    }
}
