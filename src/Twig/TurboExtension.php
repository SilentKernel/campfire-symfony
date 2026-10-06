<?php

declare(strict_types=1);

namespace App\Twig;

use App\Twig\Html\Tag;
use App\Twig\Html\TurboStream;
use App\Twig\View\ViewContext;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * turbo-rails view helpers (Turbo::FramesHelper, Turbo::StreamsHelper, Turbo::DriveHelper).
 *
 *   {{ turbo_frame_tag(room, {class: 'x'}, content) }}           dom_id(room) as the frame id
 *   {{ turbo_frame_tag([room, 'involvement'], {src: url}) }}      dom_id(room, :involvement)
 *   {{ turbo_stream_from(room, 'messages', {channel: 'RoomMessagesChannel'}) }}
 *   {{ turbo_stream('replace', message, content, {method: 'morph'}) }}
 *
 * The *_tag-less drive helpers (turbo_exempts_page_from_preview, …) return the meta tag: put them
 * in the template's `head` block, which is what `provide :head` does in Rails.
 */
final class TurboExtension extends AbstractExtension
{
    public function __construct(private readonly ViewContext $context)
    {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('turbo_frame_tag', TurboStream::frameTag(...), $safe),
            new TwigFunction('turbo_stream_from', $this->turboStreamFrom(...), $safe + ['is_variadic' => true]),
            new TwigFunction('turbo_stream', $this->turboStream(...), $safe),
            new TwigFunction('turbo_stream_all', $this->turboStreamAll(...), $safe),
            new TwigFunction('turbo_stream_action_tag', $this->turboStreamActionTag(...), $safe),
            new TwigFunction('turbo_stream_refresh_tag', TurboStream::refresh(...), $safe),
            new TwigFunction('turbo_exempts_page_from_cache', self::cacheControl(...), $safe),
            new TwigFunction('turbo_exempts_page_from_cache_tag', self::cacheControl(...), $safe),
            new TwigFunction('turbo_exempts_page_from_preview', self::previewControl(...), $safe),
            new TwigFunction('turbo_exempts_page_from_preview_tag', self::previewControl(...), $safe),
            new TwigFunction('turbo_page_requires_reload', self::requiresReload(...), $safe),
            new TwigFunction('turbo_page_requires_reload_tag', self::requiresReload(...), $safe),
            new TwigFunction('turbo_refresh_method_tag', self::refreshMethod(...), $safe),
            new TwigFunction('turbo_refresh_scroll_tag', self::refreshScroll(...), $safe),
            new TwigFunction('turbo_refreshes_with', static fn (string $method = 'replace', string $scroll = 'reset'): string => self::refreshMethod($method).self::refreshScroll($scroll), $safe),
        ];
    }

    /**
     * `turbo_stream_from(*streamables, **attributes)`: a trailing hash argument holds the
     * attributes. Renders `<turbo-cable-stream-source channel="…" signed-stream-name="…">`
     * (attributes first, then channel unless given, then signed-stream-name).
     *
     * @param list<mixed> $streamables
     */
    public function turboStreamFrom(array $streamables = []): string
    {
        $attributes = [];
        if ([] !== $streamables && \is_array($last = $streamables[array_key_last($streamables)]) && !array_is_list($last)) {
            $attributes = array_pop($streamables);
        }
        $present = array_filter($streamables, static fn (mixed $s): bool => null !== $s && '' !== $s && [] !== $s && !(\is_string($s) && '' === trim($s)));
        if ([] === $present) {
            throw new \InvalidArgumentException("streamables can't be blank");
        }

        $attributes['channel'] = isset($attributes['channel']) ? (string) $attributes['channel'] : 'Turbo::StreamsChannel';
        $attributes['signed-stream-name'] = $this->context->signedStreamName($streamables);

        return Tag::content('turbo-cable-stream-source', null, $attributes);
    }

    /** `turbo_stream.<action>(target, content, method:, **attributes)`.
     *
     * @param array<string, mixed> $attributes
     */
    public function turboStream(string $action, mixed $target, string|Markup|null $content = null, array $attributes = []): string
    {
        return TurboStream::actionTag($action, target: $target, template: $content ?? '', attributes: $attributes);
    }

    /** `turbo_stream.<action>_all(targets, content, **attributes)`.
     *
     * @param array<string, mixed> $attributes
     */
    public function turboStreamAll(string $action, mixed $targets, string|Markup|null $content = null, array $attributes = []): string
    {
        return TurboStream::actionTag($action, targets: $targets, template: $content ?? '', attributes: $attributes);
    }

    /** `turbo_stream_action_tag(action, {target:, targets:, template:, …attributes})`.
     *
     * @param array<string, mixed> $options
     */
    public function turboStreamActionTag(string $action, array $options = []): string
    {
        $target = $options['target'] ?? null;
        $targets = $options['targets'] ?? null;
        $template = $options['template'] ?? null;
        unset($options['target'], $options['targets'], $options['template']);

        return TurboStream::actionTag($action, $target, $targets, null === $template ? null : ($template instanceof Markup ? $template : (string) $template), $options);
    }

    public static function cacheControl(): string
    {
        return Tag::void('meta', ['name' => 'turbo-cache-control', 'content' => 'no-cache']);
    }

    public static function previewControl(): string
    {
        return Tag::void('meta', ['name' => 'turbo-cache-control', 'content' => 'no-preview']);
    }

    public static function requiresReload(): string
    {
        return Tag::void('meta', ['name' => 'turbo-visit-control', 'content' => 'reload']);
    }

    public static function refreshMethod(string $method = 'replace'): string
    {
        if (!\in_array($method, ['replace', 'morph'], true)) {
            throw new \InvalidArgumentException(\sprintf("Invalid refresh option '%s'", $method));
        }

        return Tag::void('meta', ['name' => 'turbo-refresh-method', 'content' => $method]);
    }

    public static function refreshScroll(string $scroll = 'reset'): string
    {
        if (!\in_array($scroll, ['reset', 'preserve'], true)) {
            throw new \InvalidArgumentException(\sprintf("Invalid scroll option '%s'", $scroll));
        }

        return Tag::void('meta', ['name' => 'turbo-refresh-scroll', 'content' => $scroll]);
    }
}
