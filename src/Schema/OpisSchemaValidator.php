<?php

declare(strict_types=1);

namespace Ingot\Schema;

use Ingot\Error\ErrorReport;
use Ingot\Error\MappingError;
use Ingot\JsonPointer;
use Ingot\Schema\Vocabulary\DateBoundsVocabulary;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;

/**
 * SchemaValidator implementation delegating to opis/json-schema
 * (drafts 06, 07, 2019-09, 2020-12).
 *
 * Leaf errors from the opis error tree are translated into MappingErrors:
 * the error code is "schema.<keyword>" and the pointer targets the offending
 * value inside the validated document.
 *
 * Two keywords come from this library rather than from a draft:
 * `formatMinimum` and `formatMaximum` bound a `"format": "date"` string, which
 * standard JSON Schema cannot express at all ({@see DateBoundKeyword}).
 *
 * `additionalProperties` gets one extra step. opis reports it once on the
 * owning object, listing every member it did not evaluate — which includes
 * *declared* members that failed their own subschema. Reported verbatim, a
 * client would read "age is not a property of this object" next to "age must
 * be >= 18". Here each undeclared member is reported at its own pointer, and
 * members that already carry a finding of their own are left out: a property
 * that broke its rule is not an unexpected property.
 */
final class OpisSchemaValidator implements SchemaValidator
{
    /** The keyword opis raises for members an object schema did not evaluate. */
    private const string UNEXPECTED_MEMBERS = 'additionalProperties';

    private const string UNEXPECTED_MEMBERS_CODE = 'schema.' . self::UNEXPECTED_MEMBERS;

    private readonly Validator $validator;
    private readonly ErrorFormatter $formatter;
    private readonly SchemaDocumentPool $pool;

    public function __construct(int $maxErrors = 100)
    {
        // The parser is built here rather than taken by default because of the
        // extra vocabulary: `formatMinimum` and `formatMaximum` are what the
        // ecosystem uses to bound a date, and a schema carrying them should be
        // enforced, not silently half-read.
        $this->validator = new Validator(
            new SchemaLoader(new SchemaParser([], [], new DateBoundsVocabulary()), new SchemaResolver()),
            max_errors: $maxErrors,
            stop_at_first_error: false,
        );
        $this->formatter = new ErrorFormatter();
        $this->pool = new SchemaDocumentPool();
    }

    public function validate(mixed $document, Schema $schema): ErrorReport
    {
        // Content-identical schemas resolve to one canonical \stdClass, so the
        // opis loader's identity cache parses each distinct schema only once.
        $error = $this->validator->validate($document, $this->pool->canonical($schema))->error();

        if ($error === null) {
            return ErrorReport::none();
        }

        return ErrorReport::of(...$this->withoutEvaluatedMembers($this->collectLeaves($error)));
    }

    /**
     * @return list<MappingError>
     */
    private function collectLeaves(ValidationError $error): array
    {
        /** @var list<ValidationError> $subErrors opis/json-schema lacks generics in its PHPDoc */
        $subErrors = $error->subErrors();

        if ($subErrors === []) {
            return $this->translate($error);
        }

        $leaves = [];

        foreach ($subErrors as $subError) {
            $leaves = [...$leaves, ...$this->collectLeaves($subError)];
        }

        return $leaves;
    }

    /**
     * @return list<MappingError> one entry per finding — an unexpected-members
     *                            error yields one entry per member
     */
    private function translate(ValidationError $error): array
    {
        /** @var list<int|string> $path opis/json-schema lacks generics in its PHPDoc */
        $path = $error->data()->fullPath();

        if ($error->keyword() === self::UNEXPECTED_MEMBERS) {
            return $this->unexpectedMembers($error, $path);
        }

        return [new MappingError(
            JsonPointer::fromSegments($path),
            \sprintf('schema.%s', $error->keyword()),
            $this->formatter->formatErrorMessage($error),
            $error->data()->value(),
        )];
    }

    /**
     * @param list<int|string> $path pointer of the object carrying the members
     *
     * @return list<MappingError>
     */
    private function unexpectedMembers(ValidationError $error, array $path): array
    {
        /** @var list<string> $members opis names the members it did not evaluate */
        $members = $error->args()['properties'];
        /** @var array<string, mixed> $object the keyword only ever fires on objects */
        $object = (array) $error->data()->value();
        $errors = [];

        foreach ($members as $member) {
            $errors[] = new MappingError(
                JsonPointer::fromSegments([...$path, $member]),
                self::UNEXPECTED_MEMBERS_CODE,
                \sprintf('The property "%s" is not allowed here.', $member),
                $object[$member],
            );
        }

        return $errors;
    }

    /**
     * Drops the unexpected-members findings for members that already broke a
     * rule of their own: opis counts a failed member as one it never evaluated,
     * so it lands in both lists.
     *
     * @param list<MappingError> $findings
     *
     * @return list<MappingError>
     */
    private function withoutEvaluatedMembers(array $findings): array
    {
        $evaluated = [];

        foreach ($findings as $finding) {
            if ($finding->code !== self::UNEXPECTED_MEMBERS_CODE) {
                $evaluated[] = $finding->pointer->toString();
            }
        }

        $kept = [];

        foreach ($findings as $finding) {
            if ($finding->code === self::UNEXPECTED_MEMBERS_CODE && \in_array($finding->pointer->toString(), $evaluated, true)) {
                continue;
            }

            $kept[] = $finding;
        }

        return $kept;
    }
}
