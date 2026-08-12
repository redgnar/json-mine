<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * @implements ObjectValidator<FormDefinition>
 */
final class UniqueFieldNamesValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        $seen = [];

        foreach ($object->fields as $index => $field) {
            if (isset($seen[$field->name])) {
                $context->addError(
                    \sprintf('/fields/%d/name', $index),
                    'form.field.duplicate-name',
                    \sprintf('Field name "%s" is not unique.', $field->name),
                    $field->name,
                );
            }

            $seen[$field->name] = true;
        }
    }
}
