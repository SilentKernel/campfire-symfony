<?php

declare(strict_types=1);

namespace App\RichText\Sanitizer;

/**
 * Loofah's URI checks for URL-valued attributes (`Loofah::HTML5::Scrub.allowed_uri?`) and the
 * `CGI.unescapeHTML` they build on.
 */
final class UriSafety
{
    /** `Loofah::HTML5::SafeList::ALLOWED_PROTOCOLS` */
    private const array ALLOWED_PROTOCOLS = [
        'afs', 'aim', 'callto', 'data', 'ed2k', 'fax', 'ftp', 'gopher', 'http', 'https', 'irc', 'line', 'mailto', 'modem', 'news', 'nntp',
        'rsync', 'rtsp', 'sftp', 'sms', 'ssh', 'tag', 'tel', 'telnet', 'urn', 'webcal', 'xmpp',
    ];

    /** `Loofah::HTML5::SafeList::ALLOWED_URI_DATA_MEDIATYPES` */
    private const array ALLOWED_URI_DATA_MEDIATYPES = ['image/gif', 'image/jpeg', 'image/png', 'text/css', 'text/plain'];

    /** `Loofah::HTML5::Scrub::CONTROL_CHARACTERS`: /[`\u0000- \u007f\u0080-ā]/ */
    private const string CONTROL_CHARACTERS = '/[`\x{0}-\x{20}\x{7F}\x{80}-\x{101}]/u';

    public static function allowedUri(string $uri): bool
    {
        $withoutControls = (string) preg_replace(self::CONTROL_CHARACTERS, '', $uri);
        $decoded = self::decodeNumericCharacterReferences(self::cgiUnescapeHtml($withoutControls));
        $s = (string) preg_replace(self::CONTROL_CHARACTERS, '', $decoded);
        $s = str_replace(['&Tab;', '&NewLine;'], '', $s);
        $s = str_replace('&colon;', ':', $s);
        $s = mb_strtolower($s, 'UTF-8');
        $protocol = self::protocolBeforeSeparator($s);
        if (null === $protocol) {
            return true;
        }
        if (!\in_array($protocol, self::ALLOWED_PROTOCOLS, true)) {
            return false;
        }
        if ('data' === $protocol) {
            $mediatype = self::dataUriMediatype($s);

            return null !== $mediatype && \in_array($mediatype, self::ALLOWED_URI_DATA_MEDIATYPES, true);
        }

        return true;
    }

    /**
     * `CGI.unescapeHTML`: the five named entities plus terminated numeric references below
     * U+10FFFF.
     */
    public static function cgiUnescapeHtml(string $value): string
    {
        if (!str_contains($value, '&')) {
            return $value;
        }

        return (string) preg_replace_callback('/&(?:apos|amp|quot|gt|lt|#[0-9]+|#[xX][0-9A-Fa-f]+);/', static function (array $m): string {
            $body = substr($m[0], 1, -1);
            $named = ['apos' => "'", 'amp' => '&', 'quot' => '"', 'gt' => '>', 'lt' => '<'];
            if (isset($named[$body])) {
                return $named[$body];
            }
            $hex = 'x' === ($body[1] ?? '') || 'X' === ($body[1] ?? '');
            $digits = ltrim(substr($body, $hex ? 2 : 1), '0');
            if (\strlen($digits) > ($hex ? 6 : 7)) {
                return $m[0];
            }
            $code = '' === $digits ? 0 : (int) ($hex ? hexdec($digits) : $digits);
            if ($code >= 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
                return $m[0];
            }

            return mb_chr($code, 'UTF-8');
        }, $value);
    }

    /**
     * `Loofah::HTML5::Scrub.decode_numeric_character_references`: `&#(x[0-9a-f]+|[0-9]+);?`
     * (case insensitive), skipping references with too many significant digits or invalid code points.
     */
    private static function decodeNumericCharacterReferences(string $value): string
    {
        if (!str_contains($value, '&#')) {
            return $value;
        }

        return (string) preg_replace_callback('/&#(?:([xX])([0-9A-Fa-f]+)|([0-9]+));?/', static function (array $m): string {
            $hex = '' !== $m[1];
            $digits = ltrim($hex ? $m[2] : ($m[3] ?? ''), '0');
            if (\strlen($digits) > ($hex ? 6 : 7)) {
                return $m[0];
            }
            $code = '' === $digits ? 0 : (int) ($hex ? hexdec($digits) : $digits);
            if ($code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
                return $m[0];
            }

            return mb_chr($code, 'UTF-8');
        }, $value);
    }

    /**
     * Matches `\A[a-z][a-z0-9+\-.]*` followed by `PROTOCOL_SEPARATOR`
     * (`/:|(&#0*58)|(&#x0*3a)|(%|&#37;)3A/i`) and returns the scheme.
     */
    private static function protocolBeforeSeparator(string $s): ?string
    {
        if (1 !== preg_match('/\A([a-z][a-z0-9+\-.]*)(?::|&#0*58|&#x0*3a|%3a|&#37;3a)/i', $s, $m)) {
            return null;
        }

        return $m[1];
    }

    /** `Loofah::HTML5::Scrub.data_uri_mediatype` */
    private static function dataUriMediatype(string $s): ?string
    {
        $rest = str_starts_with($s, 'data:') ? substr($s, 5) : $s;
        $comma = strpos($rest, ',');
        if (false === $comma) {
            return null;
        }
        $metadata = substr($rest, 0, $comma);
        if (str_ends_with($metadata, ';base64')) {
            $metadata = substr($metadata, 0, -7);
        }
        $mediatype = trim(explode(';', $metadata)[0], " \t\n\x0B\x0C\r\0");
        $valid = 1 === preg_match("~\\A[A-Za-z0-9!#$%&'*+\\-.^_`|\\~]+/[A-Za-z0-9!#$%&'*+\\-.^_`|\\~]+\\z~", $mediatype);

        return $valid ? $mediatype : 'text/plain';
    }
}
