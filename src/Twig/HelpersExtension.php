<?php

declare(strict_types=1);

namespace App\Twig;

use App\Twig\Asset\Assets;
use App\Twig\Html\Tag;
use App\Twig\View\ViewHelpers;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * The small generic helpers of reference/app/helpers: clipboard_helper, emoji_helper,
 * time_helper, translations_helper, qr_code_helper and broadcasts_helper.
 */
final class HelpersExtension extends AbstractExtension
{
    /** EmojiHelper::REACTIONS */
    public const REACTIONS = [
        '👍' => 'Thumbs up',
        '👏' => 'Clapping',
        '👋' => 'Waving hand',
        '💪' => 'Muscle',
        '❤️' => 'Red heart',
        '😂' => 'Face with tears of joy',
        '🎉' => 'Party popper',
        '🔥' => 'Fire',
    ];

    public function __construct(
        private readonly ViewHelpers $helpers,
        private readonly Assets $assets,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('button_to_copy_to_clipboard', self::buttonToCopyToClipboard(...), $safe),
            new TwigFunction('emoji_reactions', static fn (): array => self::REACTIONS),
            new TwigFunction('local_datetime_tag', self::localDatetimeTag(...), $safe),
            new TwigFunction('translations_for', self::translationsFor(...), $safe),
            new TwigFunction('translation_button', $this->translationButton(...), $safe),
            new TwigFunction('link_to_zoom_qr_code', $this->linkToZoomQrCode(...), $safe),
            new TwigFunction('broadcast_image_path', $this->assets->path(...)),
            new TwigFunction('broadcast_image_tag', $this->helpers->imageTag(...), $safe),
        ];
    }

    /** `tag.button class: "btn", data: { controller: "copy-to-clipboard", … }, &` */
    public static function buttonToCopyToClipboard(string $url, string|Markup|null $content = null): string
    {
        return Tag::content('button', $content instanceof Markup ? $content : new Markup((string) $content, 'UTF-8'), ['class' => 'btn', 'data' => [
            'controller' => 'copy-to-clipboard', 'action' => 'copy-to-clipboard#copy',
            'copy_to_clipboard_success_class' => 'btn--success', 'copy_to_clipboard_content_value' => $url,
        ]]);
    }

    /**
     * `tag.time **attributes, datetime: datetime.iso8601, data: { local_time_target: style }`
     * (times are UTC, so iso8601 ends in "Z").
     *
     * @param array<string, mixed> $attributes
     */
    public static function localDatetimeTag(\DateTimeInterface $datetime, string $style = 'time', array $attributes = []): string
    {
        $utc = \DateTimeImmutable::createFromInterface($datetime)->setTimezone(new \DateTimeZone('UTC'));

        return Tag::content('time', null, array_merge($attributes, [
            'datetime' => $utc->format('Y-m-d\TH:i:s\Z'),
            'data' => ['local_time_target' => $style],
        ]));
    }

    public static function translationsFor(string $key): string
    {
        $items = '';
        foreach (Translations::TRANSLATIONS[$key] ?? throw new \InvalidArgumentException(\sprintf('No translations for "%s".', $key)) as $language => $translation) {
            $items .= Tag::content('dt', $language).Tag::content('dd', $translation, ['class' => 'margin-none']);
        }

        return Tag::content('dl', new Markup($items, 'UTF-8'), ['class' => 'language-list']);
    }

    public function translationButton(string $key): string
    {
        $summary = Tag::content('summary', new Markup(
            $this->helpers->imageTag('globe.svg', ['size' => 20, 'aria' => ['hidden' => 'true'], 'class' => 'color-icon'])
            .Tag::content('span', 'Translate', ['class' => 'for-screen-reader']), 'UTF-8'), ['class' => 'btn', 'tabindex' => -1]);
        $menu = Tag::content('div', new Markup(self::translationsFor($key), 'UTF-8'), ['class' => 'language-list-menu shadow', 'data' => ['popup_target' => 'menu']]);

        return Tag::content('details', new Markup($summary.$menu, 'UTF-8'), ['class' => 'position-relative', 'data' => [
            'controller' => 'popup',
            'action' => 'keydown.esc->popup#close toggle->popup#toggle click@document->popup#closeOnClickOutside',
            'popup_orientation_top_class' => 'popup-orientation-top',
        ]]);
    }

    /** `link_to qr_code_path(Base64.urlsafe_encode64(url)), class: "btn", data: { lightbox_target: "image", … }, &` */
    public function linkToZoomQrCode(string $url, string|Markup|null $content = null): string
    {
        $path = $this->urlGenerator->generate('qr_code', ['id' => strtr(base64_encode($url), '+/', '-_')]);

        return $this->helpers->linkTo($content instanceof Markup ? $content : new Markup((string) $content, 'UTF-8'), $path, ['class' => 'btn', 'data' => [
            'lightbox_target' => 'image', 'action' => 'lightbox#open', 'lightbox_url_value' => $path,
        ]]);
    }
}
