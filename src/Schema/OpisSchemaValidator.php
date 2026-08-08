<?php

declare(strict_types=1);

namespace JsonMine\Schema;

use JsonMine\Error\ErrorReport;
use JsonMine\Error\MappingError;
use JsonMine\JsonPointer;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * SchemaValidator implementation delegating to opis/json-schema
 * (drafts 06, 07, 2019-09, 2020-12).
 *
 * Leaf errors from the opis error tree are translated into MappingErrors:
 * the error code is "schema.<keyword>" and the pointer targets the offending
 * value inside the validated document.
 */
final class OpisSchemaValidator implements SchemaValidator
{
    private readonly Validator $validator;
    private readonly ErrorFormatter $formatter;

    public function __construct(int $maxErrors = 100)
    {
        $this->validator = new Validator(max_errors: $maxErrors, stop_at_first_error: false);
        $this->formatter = new ErrorFormatter();
    }

    public function validate(mixed $document, Schema $schema): ErrorReport
    {
        $error = $this->validator->validate($document, $schema->document)->error();

        if ($error === null) {
            return ErrorReport::none();
        }

        return ErrorReport::of(...$this->collectLeaves($error));
    }

    /**
     * @return list<MappingError>
     */
    private function collectLeaves(ValidationError $error): array
    {
        /** @var list<ValidationError> $subErrors opis/json-schema lacks generics in its PHPDoc */
        $subErrors = $error->subErrors();

        if ($subErrors === []) {
            return [$this->translate($error)];
        }

        $leaves = [];

        foreach ($subErrors as $subError) {
            $leaves = [...$leaves, ...$this->collectLeaves($subError)];
        }

        return $leaves;
    }

    private function translate(ValidationError $error): MappingError
    {
        /** @var list<int|string> $path opis/json-schema lacks generics in its PHPDoc */
        $path = $error->data()->fullPath();

        return new MappingError(
            JsonPointer::fromSegments($path),
            \sprintf('schema.%s', $error->keyword()),
            $this->formatter->formatErrorMessage($error),
            $error->data()->value(),
        );
    }
}
