<?php

declare(strict_types=1);

namespace App\Tests\Functional\Assets;

use Symfony\Component\AssetMapper\AssetMapperInterface;

/**
 * The reference app's compiled assets (tests/fixtures/rails/assets, exported by
 * assets/script/revendor) and helpers to compare markup across the two digest schemes:
 * every /assets/ URL is replaced by "/assets/{logical path}".
 */
final class RailsAssets
{
    public const DIR = __DIR__.'/../../fixtures/rails/assets/';

    /** @return array<string, array{digested_path: string}> logical path => manifest entry */
    public static function manifest(): array
    {
        return json_decode((string) file_get_contents(self::DIR.'manifest.json'), true, flags: \JSON_THROW_ON_ERROR);
    }

    public static function fixture(string $name): string
    {
        return (string) file_get_contents(self::DIR.$name);
    }

    /** Rails' digested URLs → logical. */
    public static function normalizeRails(string $html): string
    {
        $map = [];
        foreach (self::manifest() as $logical => $entry) {
            $map['/assets/'.$entry['digested_path']] = '/assets/{'.$logical.'}';
        }

        return self::replaceUrls($html, $map);
    }

    /** Our digested URLs → logical. */
    public static function normalizeOurs(string $html, AssetMapperInterface $assetMapper): string
    {
        $map = [];
        foreach (array_keys(self::manifest()) as $logical) {
            if (null !== $path = $assetMapper->getPublicPath($logical)) {
                $map[$path] = '/assets/{'.$logical.'}';
            }
        }

        return self::replaceUrls($html, $map);
    }

    /** @param array<string, string> $map */
    private static function replaceUrls(string $html, array $map): string
    {
        return (string) preg_replace_callback('~/assets/[^"\'\s)>?#]+~', static fn (array $m): string => $map[$m[0]] ?? $m[0], $html);
    }
}
