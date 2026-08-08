<?php

declare(strict_types=1);

namespace JsonMine\Validation;

/**
 * Validates a hydrated object beyond schema and type checks.
 *
 * Implementations live outside the mapped classes (helpers/plugins of the
 * mapper) and are bound to a target class via the mapper builder. The mapper
 * invokes them post-order: after the object and its whole subtree have been
 * successfully hydrated — a validator always receives a type-safe instance.
 * Validators bound to the document root see the complete tree via
 * {@see ValidationContext::root()}.
 *
 * @template T of object
 */
interface ObjectValidator
{
    /**
     * Reports problems to the context; never throws for data errors.
     *
     * @param T $object
     */
    public function validate(object $object, ValidationContext $context): void;
}
