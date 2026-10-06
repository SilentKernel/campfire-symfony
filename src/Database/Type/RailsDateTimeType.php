<?php

declare(strict_types=1);

namespace App\Database\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;

/**
 * Active Record's SQLite datetime: UTC text, with microseconds only when there are some
 * ("2026-03-02 16:00:00", "2026-03-02 16:00:00.123456"), exactly as
 * ActiveRecord::ConnectionAdapters::Quoting#quoted_date writes it.
 *
 * Doctrine's own datetime type writes whole seconds, which would reorder messages created within
 * the same second, and always padding ".000000" would break Rails' text comparisons
 * (`where("created_at < ?", time)`) against rows Rails wrote.
 *
 * Query parameters are not typed from the mapping: bind times with this type
 * (`setParameter('t', $time, RailsDateTimeType::NAME)`), never as Doctrine's `datetime_immutable`.
 */
final class RailsDateTimeType extends Type
{
    public const NAME = 'rails_datetime';
    public const FORMAT = 'Y-m-d H:i:s';
    public const FORMAT_WITH_MICROSECONDS = 'Y-m-d H:i:s.u';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'datetime(6)';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return self::format($value);
        }

        throw InvalidType::new($value, self::NAME, ['null', \DateTimeInterface::class]);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
        }

        return self::parse((string) $value)
            ?? throw InvalidFormat::new((string) $value, self::NAME, self::FORMAT_WITH_MICROSECONDS);
    }

    /**
     * Parses what Rails (or SQLite's `STRFTIME('%Y-%m-%d %H:%M:%f', 'NOW')`, which `insert_all` uses)
     * stored: "YYYY-MM-DD HH:MM:SS" with an optional 1-6 digit fraction, as UTC. Also accepts ISO 8601.
     */
    public static function parse(string $value): ?\DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        foreach ([self::FORMAT_WITH_MICROSECONDS, self::FORMAT, 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:i:sP'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $value, $utc);
            if (false === $parsed) {
                continue;
            }
            // createFromFormat() rolls impossible dates over ("2026-02-30" becomes March 2nd).
            $errors = \DateTimeImmutable::getLastErrors();
            if (false !== $errors && $errors['warning_count'] > 0) {
                return null;
            }

            return $parsed->setTimezone($utc);
        }

        return null;
    }

    /** Formats a time the way Active Record writes it (quoted_date). */
    public static function format(\DateTimeInterface $time): string
    {
        $utc = \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'));

        return '000000' === $utc->format('u') ? $utc->format(self::FORMAT) : $utc->format(self::FORMAT_WITH_MICROSECONDS);
    }
}
