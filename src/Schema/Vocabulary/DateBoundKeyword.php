<?php

declare(strict_types=1);

namespace Ingot\Schema\Vocabulary;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Keyword;
use Opis\JsonSchema\Keywords\ErrorTrait;
use Opis\JsonSchema\Schema;
use Opis\JsonSchema\ValidationContext;

/**
 * One end of a date range: `formatMinimum` or `formatMaximum` beside
 * `"format": "date"`, with the meaning ajv-formats gives them.
 *
 * JSON Schema has no way to bound a string in time — there is `minLength`,
 * `maxLength` and `pattern`, and none of them can say "not before 2026-01-01".
 * These two keywords are the vocabulary the ecosystem settled on, so a
 * document carrying them is enforced the same way here and in a browser
 * running ajv.
 *
 * Calendar dates in `YYYY-MM-DD` form sort as strings exactly as they sort in
 * time, which is why this needs no date arithmetic — and why it is deliberately
 * restricted to that one format, where the property holds.
 */
final class DateBoundKeyword implements Keyword
{
    use ErrorTrait;

    public function __construct(
        private readonly string $keyword,
        private readonly bool $isMinimum,
        private readonly string $bound,
    ) {}

    public function validate(ValidationContext $context, Schema $schema): ?ValidationError
    {
        /**
         * Registered as a string keyword, so opis only ever runs this over a
         * string.
         *
         * @var string $value
         */
        $value = $context->currentData();

        // A string that is not a date is `format`'s business: saying it is also
        // out of range would be a second complaint about one mistake.
        if (!DateBound::isCalendarDate($value)) {
            return null;
        }

        if ($this->isMinimum ? $value >= $this->bound : $value <= $this->bound) {
            return null;
        }

        return $this->error(
            $schema,
            $context,
            $this->keyword,
            $this->isMinimum ? 'Date must not be earlier than {bound}' : 'Date must not be later than {bound}',
            ['bound' => $this->bound],
        );
    }
}
