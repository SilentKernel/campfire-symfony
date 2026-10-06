<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * ActiveStorage::Filename (activestorage/app/models/active_storage/filename.rb) with Ruby's
 * `File.basename`/`File.extname` semantics. The raw value is what `active_storage_blobs.filename`
 * stores; `sanitized` is what URLs, headers and views show.
 */
final readonly class Filename implements \Stringable
{
    private const string UNSAFE = "\u{202E}%$|:;/<>?*\"\t\r\n\\";

    public function __construct(public string $raw)
    {
    }

    public function __toString(): string
    {
        return $this->sanitized();
    }

    /** `File.basename(filename, extension_with_delimiter)` */
    public function base(): string
    {
        $base = self::basename($this->raw);
        $extension = $this->extensionWithDelimiter();
        if ('' !== $extension && \strlen($base) > \strlen($extension) && str_ends_with($base, $extension)) {
            return substr($base, 0, -\strlen($extension));
        }

        return $base;
    }

    /** `File.extname(filename)` */
    public function extensionWithDelimiter(): string
    {
        return self::extname($this->raw);
    }

    /** `extension_without_delimiter`, aliased as `extension`. */
    public function extension(): string
    {
        return substr($this->extensionWithDelimiter(), 1);
    }

    /**
     * `encode(UTF_8, invalid: :replace, undef: :replace, replace: "�").strip.tr(UNSAFE, "-")`.
     */
    public function sanitized(): string
    {
        $utf8 = self::scrub($this->raw);
        $stripped = trim($utf8, "\0\t\n\v\f\r ");
        $out = '';
        foreach (mb_str_split($stripped, 1, 'UTF-8') as $char) {
            $out .= str_contains(self::UNSAFE, $char) ? '-' : $char;
        }

        return $out;
    }

    /** Invalid UTF-8 replaced by U+FFFD, one replacement per invalid byte as Ruby's String#encode does. */
    public static function scrub(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|(.)/s',
            static fn (array $m): string => isset($m[1]) ? "\u{FFFD}" : $m[0],
            $value,
        );
    }

    /** `File.basename(path)`: the last component, ignoring trailing slashes. */
    public static function basename(string $path): string
    {
        $trimmed = rtrim($path, '/');
        if ('' === $trimmed) {
            return '' === $path ? '' : '/';
        }
        $slash = strrpos($trimmed, '/');

        return false === $slash ? $trimmed : substr($trimmed, $slash + 1);
    }

    /** `File.extname(path)` on Unix: leading dots don't start an extension; "foo." has ".". */
    public static function extname(string $path): string
    {
        $base = ltrim(self::basename($path), '.');
        $dot = strrpos($base, '.');

        return false === $dot ? '' : substr($base, $dot);
    }
}
