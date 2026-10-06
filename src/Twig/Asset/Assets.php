<?php

declare(strict_types=1);

namespace App\Twig\Asset;

use App\Twig\Html\Tag;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\AssetMapperRepository;
use Symfony\Component\AssetMapper\ImportMap\ImportMapConfigReader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Propshaft + importmap-rails asset tags over AssetMapper. Logical paths are Rails' (see
 * config/packages/asset_mapper.yaml); URLs are AssetMapper's digested public paths.
 *
 * The rendered tags only depend on the deployed assets, so outside debug they are computed once
 * per worker.
 */
final class Assets
{
    private ?string $importmapTags = null;

    /** @var list<string>|null */
    private ?array $stylesheets = null;

    public function __construct(
        private readonly AssetMapperInterface $assetMapper,
        #[Autowire(service: 'asset_mapper.repository')]
        private readonly AssetMapperRepository $repository,
        #[Autowire(service: 'asset_mapper.importmap.config_reader')]
        private readonly ImportMapConfigReader $importMapConfigReader,
        private readonly PreloadLinks $preloadLinks,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug = false,
    ) {
    }

    /**
     * `asset_path(source)`: the digested URL of a logical path ("check.svg" → "/assets/check-1a2b3c4.svg").
     * URLs and absolute paths are returned unchanged, as Rails does.
     */
    public function path(string $source): string
    {
        if ('' === $source || str_starts_with($source, '/') || preg_match('~^[a-z][a-z0-9+.-]*:~i', $source)) {
            return $source;
        }

        $path = strtok($source, '?#');

        return ($this->assetMapper->getPublicPath((string) $path) ?? throw new \InvalidArgumentException(\sprintf('The asset "%s" was not found in the load path.', $path)))
            .substr($source, \strlen((string) $path));
    }

    /**
     * `stylesheet_link_tag(*sources, **options)`; the source :all (written "all") means every
     * stylesheet on the load path, sorted by logical path as Propshaft does. Each href is also
     * queued for the response's `Link: rel=preload` header.
     *
     * @param list<string>         $sources
     * @param array<string, mixed> $options
     */
    public function stylesheetLinkTags(array $sources, array $options = []): string
    {
        $logicalPaths = [];
        foreach ($sources as $source) {
            if ('all' === $source || ':all' === $source) {
                array_push($logicalPaths, ...$this->allStylesheets());
            } else {
                $logicalPaths[] = str_ends_with($source, '.css') || str_contains($source, '://') ? $source : $source.'.css';
            }
        }

        $tags = [];
        foreach (array_unique($logicalPaths) as $logicalPath) {
            $href = $this->path($logicalPath);
            $this->preloadLinks->add('<'.$href.'>; rel=preload; as=style; nopush');
            $tags[] = Tag::legacy('link', ['rel' => 'stylesheet', 'href' => $href] + $options);
        }

        return implode("\n", $tags);
    }

    /**
     * `javascript_importmap_tags(entry_point)`: the inline importmap (JSON.pretty_generate), a
     * modulepreload link for every pin (importmap-rails preloads all pins by default), and the
     * module script importing the entry point.
     */
    public function javascriptImportmapTags(string $entryPoint = 'application'): string
    {
        if (!$this->debug && null !== $this->importmapTags && 'application' === $entryPoint) {
            return $this->importmapTags;
        }

        $imports = [];
        foreach ($this->importMapConfigReader->getEntries() as $entry) {
            $imports[$entry->importName] = $this->path($entry->path);
        }

        $json = json_encode(['imports' => $imports], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        // JSON.pretty_generate indents by two spaces.
        $json = (string) preg_replace_callback('/^ +/m', static fn (array $m): string => str_repeat(' ', intdiv(\strlen($m[0]), 2)), $json);

        $html = [Tag::content('script', $json, ['type' => 'importmap', 'data-turbo-track' => 'reload'], false)];
        foreach ($imports as $path) {
            $html[] = Tag::void('link', ['rel' => 'modulepreload', 'href' => $path]);
        }
        $html[] = Tag::content('script', 'import "'.$entryPoint.'"', ['type' => 'module'], false);

        $tags = implode("\n", $html);
        if ('application' === $entryPoint) {
            $this->importmapTags = $tags;
        }

        return $tags;
    }

    /** @return list<string> logical paths of every stylesheet, in Propshaft's :all order */
    public function allStylesheets(): array
    {
        if (!$this->debug && null !== $this->stylesheets) {
            return $this->stylesheets;
        }

        $stylesheets = array_values(array_filter(array_map('strval', array_keys($this->repository->all())), static fn (string $path): bool => str_ends_with($path, '.css')));
        sort($stylesheets, \SORT_STRING);

        return $this->stylesheets = $stylesheets;
    }
}
