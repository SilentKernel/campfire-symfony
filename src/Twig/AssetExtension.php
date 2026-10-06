<?php

declare(strict_types=1);

namespace App\Twig;

use App\Twig\Asset\Assets;
use App\Twig\View\ViewContext;
use App\Twig\View\ViewHelpers;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Asset helpers with Rails' names and logical paths: `asset_path("bell.mp3")`,
 * `image_tag("check.svg", {size: 24})`, `stylesheet_link_tag("all", {"data-turbo-track": "reload"})`,
 * `javascript_importmap_tags()`.
 */
final class AssetExtension extends AbstractExtension
{
    public function __construct(
        private readonly Assets $assets,
        private readonly ViewHelpers $helpers,
        private readonly ViewContext $context,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('asset_path', $this->assets->path(...)),
            new TwigFunction('image_path', $this->assets->path(...)),
            new TwigFunction('audio_path', $this->assets->path(...)),
            new TwigFunction('asset_url', $this->assetUrl(...)),
            new TwigFunction('image_url', $this->assetUrl(...)),
            new TwigFunction('image_tag', $this->helpers->imageTag(...), $safe),
            new TwigFunction('stylesheet_link_tag', $this->stylesheetLinkTag(...), $safe + ['is_variadic' => true]),
            new TwigFunction('stylesheet_link_tags', $this->stylesheetLinkTag(...), $safe + ['is_variadic' => true]),
            new TwigFunction('javascript_importmap_tags', $this->assets->javascriptImportmapTags(...), $safe),
        ];
    }

    /**
     * `stylesheet_link_tag(*sources, **options)`: a trailing hash argument holds the options.
     *
     * @param list<mixed> $sources
     */
    public function stylesheetLinkTag(array $sources = []): string
    {
        $options = [];
        if ([] !== $sources && \is_array($sources[array_key_last($sources)])) {
            $options = array_pop($sources);
        }

        return $this->assets->stylesheetLinkTags(array_map('strval', $sources), $options);
    }

    /** `asset_url` / `image_url`: the digested path on the current request's host. */
    public function assetUrl(string $source): string
    {
        $path = $this->assets->path($source);
        $request = $this->context->request();

        return null !== $request && str_starts_with($path, '/') ? $request->getSchemeAndHttpHost().$path : $path;
    }
}
