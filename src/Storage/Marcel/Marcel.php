<?php

declare(strict_types=1);

namespace App\Storage\Marcel;

use App\Storage\Filename;

/**
 * Marcel 1.1.0 content type identification (`Marcel::MimeType.for`), over the gem's own tables
 * (MarcelTables, dumped from the reference image's bundle).
 */
final class Marcel
{
    public const string BINARY = 'application/octet-stream';

    private static ?int $prefixLength = null;

    /**
     * `Marcel::MimeType.for(io, name:, declared_type:)`, as `ActiveStorage::Blob#extract_content_type`
     * calls it. `$data` needs only the first magicPrefixLength() bytes of the file.
     */
    public static function identify(string $data, ?string $name = null, ?string $declaredType = null): string
    {
        $filenameType = null === $name ? null : self::byPath($name);

        return self::mostSpecificType([self::byMagic($data), self::forDeclaredType($declaredType), $filenameType]);
    }

    /** `Marcel::MimeType.for(extension:)` */
    public static function forExtension(string $extension): string
    {
        return self::mostSpecificType([self::byExtension($extension)]);
    }

    /** `Marcel::Magic.by_extension(ext)&.type&.downcase`: case-insensitive, leading dot optional. */
    public static function byExtension(string $extension): ?string
    {
        $extension = strtolower($extension);
        if (str_starts_with($extension, '.')) {
            $extension = substr($extension, 1);
        }
        $type = MarcelTables::EXTENSIONS[$extension] ?? null;

        return null === $type ? null : strtolower($type);
    }

    /** `Marcel::Magic.by_path`: the extension per Ruby's `File.extname`. */
    public static function byPath(string $path): ?string
    {
        return self::byExtension(Filename::extname($path));
    }

    /**
     * `Marcel::Magic.new(type).extensions`.
     *
     * @return list<string>
     */
    public static function extensions(string $contentType): array
    {
        return array_map(strval(...), MarcelTables::TYPE_EXTS[$contentType] ?? []);
    }

    /** `Marcel::Magic.child?` */
    public static function isChild(string $child, string $parent): bool
    {
        if ($child === $parent) {
            return true;
        }
        foreach (MarcelTables::TYPE_PARENTS[$child] ?? [] as $grandparent) {
            if (self::isChild($grandparent, $parent)) {
                return true;
            }
        }

        return false;
    }

    /** `Marcel::Magic.by_magic(io)&.type&.downcase`: the first MAGIC entry whose matches hit. */
    public static function byMagic(string $data): ?string
    {
        foreach (MarcelTables::MAGIC as [$type, $matches]) {
            if (self::matchesAny($data, $matches)) {
                return strtolower($type);
            }
        }

        return null;
    }

    /** How many leading bytes byMagic() can look at. */
    public static function magicPrefixLength(): int
    {
        if (null !== self::$prefixLength) {
            return self::$prefixLength;
        }
        $reach = static function (array $matches) use (&$reach): int {
            $max = 0;
            foreach ($matches as [$offset, $rangeEnd, $value, $children]) {
                $length = null === $value ? 0 : \strlen($value);
                $max = max($max, (null === $rangeEnd ? $offset : $rangeEnd) + $length, $reach($children));
            }

            return $max;
        };
        $max = 0;
        foreach (MarcelTables::MAGIC as [, $matches]) {
            $max = max($max, $reach($matches));
        }

        return self::$prefixLength = $max;
    }

    /** @param list<array{int, ?int, ?string, list<mixed>}> $matches */
    private static function matchesAny(string $data, array $matches): bool
    {
        foreach ($matches as [$offset, $rangeEnd, $value, $children]) {
            if (null === $value) {
                continue;
            }
            if (null !== $rangeEnd) {
                // io.read(offset.begin); io.read(offset.end - offset.begin + value.bytesize).include?(value)
                $window = self::read($data, $offset, $rangeEnd - $offset + \strlen($value));
                $hit = null !== $window && ('' === $value || str_contains($window, $value));
            } else {
                $hit = self::read($data, $offset, \strlen($value)) === $value;
            }
            if ($hit && ([] === $children || self::matchesAny($data, $children))) {
                return true;
            }
        }

        return false;
    }

    /** `IO#read(length)` after skipping `offset` bytes: null at EOF, otherwise up to `length` bytes. */
    private static function read(string $data, int $offset, int $length): ?string
    {
        if (0 === $length) {
            return '';
        }
        if ($offset >= \strlen($data)) {
            return null;
        }

        return substr($data, $offset, $length);
    }

    /** Declared types are downcased, cut at the first `;`, `,` or space, and ignored when binary. */
    private static function forDeclaredType(?string $declaredType): ?string
    {
        if (null === $declaredType) {
            return null;
        }
        $type = preg_split('/[;,\s]/', strtolower($declaredType), 2)[0] ?? '';
        if ('' === $type || !str_contains($type, '/')) {
            return null;
        }

        return self::BINARY === $type ? null : $type;
    }

    /** @param list<?string> $candidates */
    private static function mostSpecificType(array $candidates): string
    {
        $unique = [];
        foreach ([...$candidates, self::BINARY] as $candidate) {
            if (null !== $candidate && !\in_array($candidate, $unique, true)) {
                $unique[] = $candidate;
            }
        }
        $pick = array_shift($unique);
        foreach ($unique as $candidate) {
            if (self::isChild($candidate, $pick)) {
                $pick = $candidate;
            }
        }

        return $pick;
    }
}
