<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * `ActionDispatch::Http::ContentDisposition.format(disposition:, filename:)` (actionpack
 * action_dispatch/http/content_disposition.rb): an ASCII `filename=` transliterated with I18n's
 * default approximations, and the full name in RFC 5987's `filename*=`.
 */
final class ContentDisposition
{
    public static function format(string $disposition, ?string $filename = null): string
    {
        if (null === $filename) {
            return $disposition;
        }

        return \sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $disposition,
            self::percentEscape(self::transliterate($filename), '/[^ A-Za-z0-9!#$+.^_`|~-]/'),
            self::percentEscape($filename, '/[^A-Za-z0-9!#$&+.^_`|~-]/'),
        );
    }

    /**
     * `ActiveStorage::Service#content_disposition_with`: anything but "attachment" is "inline".
     */
    public static function with(?string $type, string $sanitizedFilename): string
    {
        return self::format('attachment' === $type ? 'attachment' : 'inline', $sanitizedFilename);
    }

    /** `I18n.transliterate`: default approximations, "?" for any other non-ASCII character. */
    public static function transliterate(string $value): string
    {
        return (string) preg_replace_callback(
            '/[^\x00-\x7F]/u',
            static fn (array $m): string => Approximations::MAP[$m[0]] ?? '?',
            Filename::scrub($value),
        );
    }

    private static function percentEscape(string $value, string $pattern): string
    {
        return (string) preg_replace_callback(
            $pattern.'u',
            static fn (array $m): string => strtoupper(implode('', array_map(static fn (string $b): string => '%'.bin2hex($b), str_split($m[0])))),
            Filename::scrub($value),
        );
    }
}
