<?php

declare(strict_types=1);

namespace Ingot\Tests\Mapping;

use Ingot\Coercion;
use Ingot\MapperBuilder;
use Ingot\Source;
use Ingot\Tests\Fixture\BadFormatTarget;
use Ingot\Tests\Fixture\Contact;
use Ingot\Tests\Fixture\Event;
use Ingot\Tests\Fixture\FormattedProp;
use Ingot\Tests\Fixture\Reservation;
use Ingot\Tests\Fixture\UnknownFormat;
use Ingot\Tests\Fixture\UuidDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * #[Format] behavior: string members validated against the named format,
 * \DateTimeImmutable members restricted to the strict syntax, all through
 * the public API.
 */
final class FormatTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validFormattedStrings(): iterable
    {
        yield 'uuid' => ['id', '9f4a2f6e-0b1c-4d3e-8a5f-6b7c8d9e0f1a'];
        yield 'uuid uppercase' => ['id', '9F4A2F6E-0B1C-4D3E-8A5F-6B7C8D9E0F1A'];
        yield 'email' => ['email', 'ada@example.com'];
        yield 'uri' => ['website', 'https://example.com/a?b=1'];
        yield 'uri urn' => ['website', 'urn:isbn:0451450523'];
        yield 'uri uppercase scheme' => ['website', 'HTTPS://example.com/x'];
        yield 'date' => ['birthday', '2024-02-29'];
        yield 'date-time utc' => ['lastSeen', '2026-12-31T23:59:59Z'];
        yield 'date-time leap day' => ['lastSeen', '2024-02-29T10:00:00Z'];
        yield 'date-time lowercase separators' => ['lastSeen', '2026-01-02t03:04:05z'];
        yield 'date-time fraction' => ['lastSeen', '2026-01-02T03:04:05.123Z'];
        yield 'date-time offset' => ['lastSeen', '2026-01-02T03:04:05+02:00'];
        yield 'date-time negative offset' => ['lastSeen', '2026-01-02T03:04:05-05:30'];
    }

    #[DataProvider('validFormattedStrings')]
    public function testAcceptsAStringMatchingItsDeclaredFormat(string $key, string $value): void
    {
        // GIVEN #[Format] attributes on Contact's string members
        $mapper = MapperBuilder::create()->build();
        $document = json_encode(['id' => '9f4a2f6e-0b1c-4d3e-8a5f-6b7c8d9e0f1a', $key => $value], \JSON_THROW_ON_ERROR);

        // WHEN
        $contact = $mapper->map(Contact::class, Source::json($document));

        // THEN the value arrives unchanged — formats validate, never convert
        self::assertSame($value, match ($key) {
            'id' => $contact->id,
            'email' => $contact->email,
            'website' => $contact->website,
            'birthday' => $contact->birthday,
            'lastSeen' => $contact->lastSeen,
            default => self::fail(\sprintf('Unexpected member "%s".', $key)),
        });
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidFormattedStrings(): iterable
    {
        yield 'uuid too short' => ['id', '9f4a2f6e-0b1c-4d3e-8a5f', 'uuid'];
        yield 'uuid bad character' => ['id', '9f4a2f6g-0b1c-4d3e-8a5f-6b7c8d9e0f1a', 'uuid'];
        yield 'uuid with junk prefix' => ['id', 'x9f4a2f6e-0b1c-4d3e-8a5f-6b7c8d9e0f1a', 'uuid'];
        yield 'uuid with junk suffix' => ['id', '9f4a2f6e-0b1c-4d3e-8a5f-6b7c8d9e0f1ax', 'uuid'];
        yield 'email' => ['email', 'not-an-email', 'email'];
        yield 'uri without scheme' => ['website', 'example.com/foo', 'uri'];
        yield 'uri with space' => ['website', 'https://example.com/a b', 'uri'];
        yield 'uri scheme starting with digit' => ['website', '1http://example.com', 'uri'];
        yield 'date without zero padding' => ['birthday', '2026-1-2', 'date'];
        yield 'date that does not exist' => ['birthday', '2026-02-29', 'date'];
        yield 'date month out of range' => ['birthday', '2026-13-01', 'date'];
        yield 'date with junk prefix' => ['birthday', 'x2026-08-13', 'date'];
        yield 'date with junk suffix' => ['birthday', '2026-08-13x', 'date'];
        yield 'date-time without offset' => ['lastSeen', '2026-01-02T03:04:05', 'date-time'];
        yield 'date-time with space separator' => ['lastSeen', '2026-01-02 03:04:05Z', 'date-time'];
        yield 'date-time hour out of range' => ['lastSeen', '2026-01-02T24:00:00Z', 'date-time'];
        yield 'date-time minute out of range' => ['lastSeen', '2026-01-02T03:60:00Z', 'date-time'];
        yield 'date-time second out of range' => ['lastSeen', '2026-01-02T03:04:60Z', 'date-time'];
        yield 'date-time day that does not exist' => ['lastSeen', '2026-02-30T10:00:00Z', 'date-time'];
        yield 'date-time month out of range' => ['lastSeen', '2026-13-01T10:00:00Z', 'date-time'];
        yield 'date-time with junk prefix' => ['lastSeen', 'x2026-01-02T03:04:05Z', 'date-time'];
        yield 'date-time with junk suffix' => ['lastSeen', '2026-01-02T03:04:05Zx', 'date-time'];
    }

    #[DataProvider('invalidFormattedStrings')]
    public function testRejectsAStringNotMatchingItsDeclaredFormat(string $key, string $value, string $format): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();
        $document = json_encode(['id' => '9f4a2f6e-0b1c-4d3e-8a5f-6b7c8d9e0f1a', $key => $value], \JSON_THROW_ON_ERROR);

        // WHEN
        $result = $mapper->tryMap(Contact::class, Source::json($document));

        // THEN
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.format', $error->code);
        self::assertSame('/' . $key, $error->pointer->toString());
        self::assertSame(\sprintf('"%s" is not a valid %s.', $value, $format), $error->message);
        self::assertSame($value, $error->input);
    }

    public function testNullPassesANullableFormattedMember(): void
    {
        // GIVEN Contact::$email is ?string with #[Format('email')]
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $contact = $mapper->map(Contact::class, Source::json('{"id": "9f4a2f6e-0b1c-4d3e-8a5f-6b7c8d9e0f1a", "email": null}'));

        // THEN
        self::assertNull($contact->email);
    }

    public function testFormatAppliesToANonConstructorProperty(): void
    {
        // GIVEN #[Format('uuid')] on a plain public property
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(FormattedProp::class, Source::json('{"id": "nope"}'));

        // THEN
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.format', $error->code);
        self::assertSame('/id', $error->pointer->toString());
    }

    public function testLaxCoercionRunsBeforeTheFormatCheck(): void
    {
        // GIVEN Lax mode stringifies the int, and only then the format applies
        $mapper = MapperBuilder::create()->withCoercion(Coercion::Lax)->build();

        // WHEN
        $result = $mapper->tryMap(Contact::class, Source::json('{"id": 12345}'));

        // THEN the coerced "12345" fails the uuid check — not the type check
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.format', $error->code);
        self::assertSame('"12345" is not a valid uuid.', $error->message);
    }

    public function testDateTimeFormatMakesDateParsingStrict(): void
    {
        // GIVEN Event::$createdAt carries #[Format('date-time')]
        $mapper = MapperBuilder::create()->build();

        // WHEN PHP's lenient parser would happily accept "tomorrow"
        $result = $mapper->tryMap(Event::class, Source::json('{"title": "x", "created_at": "tomorrow"}'));

        // THEN the declared format rejects it first
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.format', $error->code);
        self::assertSame('/created_at', $error->pointer->toString());
        self::assertSame('"tomorrow" is not a valid date-time.', $error->message);
    }

    public function testDateTimeFormatKeepsTheInstantOfAnOffsetValue(): void
    {
        // GIVEN
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $event = $mapper->map(Event::class, Source::json('{"title": "x", "created_at": "2026-08-08T12:00:00+02:00"}'));

        // THEN
        self::assertSame('2026-08-08T10:00:00+00:00', $event->createdAt->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::RFC3339));
    }

    public function testDateFormatAcceptsAFullDateIntoDateTimeImmutable(): void
    {
        // GIVEN Reservation::$day carries #[Format('date')]
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $reservation = $mapper->map(Reservation::class, Source::json('{"day": "2026-08-13"}'));

        // THEN
        self::assertSame('2026-08-13', $reservation->day->format('Y-m-d'));
        self::assertNull($reservation->until);
    }

    public function testDateFormatRejectsADateTimeString(): void
    {
        // GIVEN a 'date' member must not silently accept full timestamps
        $mapper = MapperBuilder::create()->build();

        // WHEN
        $result = $mapper->tryMap(Reservation::class, Source::json('{"day": "2026-08-13T10:00:00Z"}'));

        // THEN
        $error = $result->errors()->errors[0];
        self::assertSame('mapping.format', $error->code);
        self::assertSame('"2026-08-13T10:00:00Z" is not a valid date.', $error->message);
    }

    public function testFormatOnANonStringMemberIsAConfigurationError(): void
    {
        // GIVEN #[Format('uuid')] on an int parameter
        $mapper = MapperBuilder::create()->build();

        // THEN
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/does not apply to parameter "id"/');

        // WHEN
        $mapper->map(BadFormatTarget::class, Source::json('{"id": 1}'));
    }

    public function testDateTimeMemberAcceptsOnlyDateFormats(): void
    {
        // GIVEN #[Format('uuid')] on a \DateTimeImmutable parameter
        $mapper = MapperBuilder::create()->build();

        // THEN
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/does not apply to parameter "at"/');

        // WHEN
        $mapper->map(UuidDate::class, Source::json('{"at": "2026-01-01T00:00:00Z"}'));
    }

    public function testUnknownFormatNameIsAConfigurationError(): void
    {
        // GIVEN #[Format('hostname')], which the engine cannot validate
        $mapper = MapperBuilder::create()->build();

        // THEN the supported set is spelled out
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Unknown format "hostname".*date-time, date, uuid, uri, email/');

        // WHEN
        $mapper->map(UnknownFormat::class, Source::json('{"server": "example.com"}'));
    }
}
