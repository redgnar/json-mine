<?php

declare(strict_types=1);

namespace JsonMine\Mapping;

use JsonMine\Validation\ObjectValidator;

/**
 * Validators bound to target classes. A validator runs for every hydrated
 * object that is an instance of its bound class (instanceof semantics, so
 * bindings to interfaces and parent classes work). Factories are resolved
 * lazily, once.
 */
final class ValidatorRegistry
{
    /** @var list<array{string, ObjectValidator<object>|\Closure(): ObjectValidator<object>}> */
    private array $bindings = [];

    private bool $empty = true;

    /**
     * @param class-string $class
     * @param ObjectValidator<object>|\Closure(): ObjectValidator<object> $validator
     */
    public function add(string $class, ObjectValidator|\Closure $validator): void
    {
        $this->bindings[] = [$class, $validator];
        $this->empty = false;
    }

    public function isEmpty(): bool
    {
        return $this->empty;
    }

    /**
     * @return list<ObjectValidator<object>>
     */
    public function for(object $object): array
    {
        $validators = [];

        foreach ($this->bindings as $index => [$class, $validator]) {
            if (!$object instanceof $class) {
                continue;
            }

            if ($validator instanceof \Closure) {
                $validator = $validator();
                $this->bindings[$index][1] = $validator;
            }

            $validators[] = $validator;
        }

        return $validators;
    }
}
