<?php

declare(strict_types=1);

namespace Ingot\Attribute;

/**
 * Constrains the accepted syntax of a string or \DateTimeImmutable member.
 *
 * Supported formats: 'date-time' (RFC 3339), 'date' (full-date), 'uuid',
 * 'uri', 'email'. A non-matching value is a data error (code
 * "mapping.format"); an unsupported format name or a member of any other
 * type is a configuration error. On \DateTimeImmutable members ('date-time'
 * and 'date' only) the format replaces PHP's lenient date parsing with the
 * strict syntax, and normalize() re-emits 'date' members in the full-date
 * form. SchemaGenerator copies the format into the generated schema.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Format
{
    public function __construct(
        public string $format,
    ) {}
}
