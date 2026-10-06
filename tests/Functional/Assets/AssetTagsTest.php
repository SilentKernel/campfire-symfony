<?php

declare(strict_types=1);

namespace App\Tests\Functional\Assets;

use App\Twig\Asset\Assets;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\ImportMap\ImportMapConfigReader;

/**
 * The asset tags against the reference app's own output (javascript_importmap_tags and
 * stylesheet_link_tag :all rendered by assets/script/export_reference.rb), digests aside.
 */
final class AssetTagsTest extends KernelTestCase
{
    public function testJavascriptImportmapTagsMatchRails(): void
    {
        self::assertSame(
            RailsAssets::normalizeRails(RailsAssets::fixture('javascript_importmap_tags.html')),
            RailsAssets::normalizeOurs($this->assets()->javascriptImportmapTags(), $this->assetMapper()),
        );
    }

    public function testStylesheetLinkTagAllMatchesRails(): void
    {
        self::assertSame(
            RailsAssets::normalizeRails(RailsAssets::fixture('stylesheet_link_tag_all.html')),
            RailsAssets::normalizeOurs($this->assets()->stylesheetLinkTags(['all'], ['data-turbo-track' => 'reload']), $this->assetMapper()),
        );
    }

    public function testImportmapKeysAreRailsAndEveryEntryResolves(): void
    {
        preg_match('~<script type="importmap"[^>]*>(.*?)</script>~s', RailsAssets::fixture('javascript_importmap_tags.html'), $m);
        $rails = json_decode($m[1], true, flags: \JSON_THROW_ON_ERROR)['imports'];
        $reader = self::getContainer()->get('asset_mapper.importmap.config_reader');
        \assert($reader instanceof ImportMapConfigReader);

        $keys = [];
        foreach ($reader->getEntries() as $entry) {
            $keys[] = $entry->importName;
            $asset = $this->assetMapper()->getAsset($entry->path);
            self::assertNotNull($asset, \sprintf('importmap entry "%s" (%s) does not resolve', $entry->importName, $entry->path));
            self::assertFileExists($asset->sourcePath);
            self::assertSame(RailsAssets::manifest()[$entry->path]['digested_path'], substr($rails[$entry->importName], \strlen('/assets/')), $entry->importName);
        }
        self::assertSame(array_keys($rails), $keys);
    }

    public function testEveryRailsAssetIsMappedAndCompilesLikeRails(): void
    {
        $compiled = json_decode(RailsAssets::fixture('compiled_sha256.json'), true, flags: \JSON_THROW_ON_ERROR);
        foreach (RailsAssets::manifest() as $logical => $entry) {
            $asset = $this->assetMapper()->getAsset($logical);
            self::assertNotNull($asset, $logical);
            $content = $asset->content ?? (string) file_get_contents($asset->sourcePath);
            if (str_ends_with($logical, '.css')) {
                // url() references carry digests: compared after normalization.
                $rails = RailsAssets::normalizeRails(RailsAssets::fixture('compiled/'.$entry['digested_path']));
                self::assertSame($rails, RailsAssets::normalizeOurs($content, $this->assetMapper()), $logical);
                continue;
            }
            if (preg_match('~^//# sourceMappingURL=(\S+)$~m', $content, $map)) {
                // Propshaft writes "/assets/x-digest.js.map", AssetMapper a relative URL to the same file.
                self::assertSame(
                    preg_replace('~^//# sourceMappingURL=.*$~m', '', (string) file_get_contents($asset->sourcePath)),
                    preg_replace('~^//# sourceMappingURL=.*$~m', '', $content),
                    $logical,
                );
                $mapUrl = str_starts_with($map[1], '/') ? $map[1] : \dirname($asset->publicPath).'/'.$map[1];
                self::assertSame($mapUrl, $this->assetMapper()->getPublicPath($logical.'.map'), $logical);
                continue;
            }
            self::assertSame($compiled[$entry['digested_path']], hash('sha256', $content), $logical.' differs from the reference app\'s compiled file');
        }
    }

    public function testCssUrlReferencesResolve(): void
    {
        $publicPaths = [];
        foreach (array_keys(RailsAssets::manifest()) as $logical) {
            $publicPaths[(string) $this->assetMapper()->getPublicPath($logical)] = true;
        }

        $checked = 0;
        foreach (RailsAssets::manifest() as $logical => $entry) {
            if (!str_ends_with($logical, '.css')) {
                continue;
            }
            $content = (string) $this->assetMapper()->getAsset($logical)?->content;
            preg_match_all('~url\(\s*["\']?([^"\')]+)["\']?\s*\)~', $content, $matches);
            foreach ($matches[1] as $url) {
                if (str_starts_with($url, 'data:') || str_starts_with($url, '#')) {
                    continue;
                }
                self::assertArrayHasKey(strtok($url, '?#'), $publicPaths, \sprintf('%s references %s', $logical, $url));
                ++$checked;
            }
        }
        self::assertGreaterThan(0, $checked);
    }

    private function assets(): Assets
    {
        $assets = self::getContainer()->get(Assets::class);
        \assert($assets instanceof Assets);

        return $assets;
    }

    private function assetMapper(): AssetMapperInterface
    {
        $mapper = self::getContainer()->get(AssetMapperInterface::class);
        \assert($mapper instanceof AssetMapperInterface);

        return $mapper;
    }
}
