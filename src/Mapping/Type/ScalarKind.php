<?php

declare(strict_types=1);

namespace JsonMine\Mapping\Type;

enum ScalarKind
{
    case Integer;
    case Float;
    case String;
    case Boolean;

    public function label(): string
    {
        return match ($this) {
            self::Integer => 'int',
            self::Float => 'float',
            self::String => 'string',
            self::Boolean => 'bool',
        };
    }
}
