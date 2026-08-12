<?php

declare(strict_types=1);

namespace Ingot\Tests\Fixture;

use Ingot\Validation\ObjectValidator;
use Ingot\Validation\ValidationContext;

/**
 * Reports one fixed error for every validated object.
 *
 * @implements ObjectValidator<object>
 */
final class AlwaysErrorValidator implements ObjectValidator
{
    public function validate(object $object, ValidationContext $context): void
    {
        $context->addError('', 'always.error', 'Reported unconditionally.');
    }
}
