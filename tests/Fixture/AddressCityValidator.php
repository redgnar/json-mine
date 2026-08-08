<?php

declare(strict_types=1);

namespace JsonMine\Tests\Fixture;

use JsonMine\Validation\ObjectValidator;
use JsonMine\Validation\ValidationContext;

/**
 * Node-level validator: runs for every hydrated Address anywhere in the
 * document and can see the document root.
 *
 * @implements ObjectValidator<Address>
 */
final class AddressCityValidator implements ObjectValidator
{
    public mixed $observedRoot = null;

    public function validate(object $object, ValidationContext $context): void
    {
        $this->observedRoot = $context->root();

        if ($object->city === '') {
            $context->addError('/city', 'address.city.empty', 'City must not be empty.', $object->city);
        }
    }
}
