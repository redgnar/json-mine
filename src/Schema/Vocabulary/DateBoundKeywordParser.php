<?php

declare(strict_types=1);

namespace Ingot\Schema\Vocabulary;

use Opis\JsonSchema\Info\SchemaInfo;
use Opis\JsonSchema\Keyword;
use Opis\JsonSchema\Parsers\KeywordParser;
use Opis\JsonSchema\Parsers\SchemaParser;

/**
 * Reads one end of a date range out of a schema, and refuses it here rather
 * than at validation time: a bound that is not a date, or one sitting beside a
 * format whose values do not sort chronologically as strings, is a mistake in
 * the schema and should be reported to whoever wrote it.
 */
final class DateBoundKeywordParser extends KeywordParser
{
    public function __construct(
        private readonly string $name,
        private readonly bool $isMinimum,
    ) {
        parent::__construct($name);
    }

    public function type(): string
    {
        return self::TYPE_STRING;
    }

    public function parse(SchemaInfo $info, SchemaParser $parser, object $shared): ?Keyword
    {
        $schema = $info->data();

        if (!\is_object($schema) || !$this->keywordExists($schema)) {
            return null;
        }

        $value = $this->keywordValue($schema);

        if (!\is_string($value) || !DateBound::isCalendarDate($value)) {
            throw $this->keywordException('{keyword} must be a calendar date in YYYY-MM-DD form', $info);
        }

        if (($schema->format ?? null) !== 'date') {
            throw $this->keywordException('{keyword} only means anything beside "format": "date"', $info);
        }

        return new DateBoundKeyword($this->name, $this->isMinimum, $value);
    }
}
