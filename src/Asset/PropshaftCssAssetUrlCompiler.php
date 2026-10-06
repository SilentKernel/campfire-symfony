<?php

declare(strict_types=1);

namespace App\Asset;

use Psr\Log\LoggerInterface;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\Compiler\AssetCompilerInterface;
use Symfony\Component\AssetMapper\MappedAsset;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Propshaft::Compiler::CssAssetUrls in place of AssetMapper's CSS compiler: url() references are
 * resolved against the stylesheet's *logical* directory (Campfire's stylesheets say `url(cancel.svg)`
 * for app/assets/images/cancel.svg, which works because both load paths share the root) and
 * rewritten to absolute digested URLs (`url("/assets/cancel-1a2b3c4d.svg")`).
 */
#[AsDecorator('asset_mapper.compiler.css_asset_url_compiler')]
final class PropshaftCssAssetUrlCompiler implements AssetCompilerInterface
{
    // propshaft/lib/propshaft/compiler/css_asset_urls.rb ASSET_URL_PATTERN
    private const ASSET_URL_PATTERN = '~url\(\s*["\']?(?!(?:\#|%23|data:|http:|https:|//))([^"\'\s?#)]+)([#?][^"\')]+)?\s*["\']?\)~';

    public function __construct(
        #[Autowire(service: 'logger')]
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function supports(MappedAsset $asset): bool
    {
        return 'css' === $asset->publicExtension;
    }

    public function compile(string $content, MappedAsset $asset, AssetMapperInterface $assetMapper): string
    {
        $directory = \dirname($asset->logicalPath);
        $directory = '.' === $directory ? '' : $directory.'/';

        return (string) preg_replace_callback(self::ASSET_URL_PATTERN, function (array $match) use ($asset, $assetMapper, $directory): string {
            $resolved = self::resolvePath($directory, $match[1]);
            $dependency = $assetMapper->getAsset($resolved);
            if (null === $dependency) {
                $this->logger?->warning(\sprintf("Unable to resolve '%s' for missing asset '%s' in %s", $match[1], $resolved, $asset->logicalPath));

                return 'url("'.$match[1].'")';
            }
            $asset->addDependency($dependency);

            return 'url("'.$dependency->publicPath.($match[2] ?? '').'")';
        }, $content);
    }

    private static function resolvePath(string $directory, string $filename): string
    {
        if (str_starts_with($filename, '/')) {
            return substr($filename, 1);
        }
        if (str_starts_with($filename, './')) {
            $filename = substr($filename, 2);
        }

        $parts = [];
        foreach (explode('/', $directory.$filename) as $part) {
            if ('..' === $part) {
                array_pop($parts);
            } elseif ('' !== $part && '.' !== $part) {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }
}
