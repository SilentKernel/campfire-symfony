<?php

declare(strict_types=1);

namespace App\RichText\Sanitizer;

/**
 * A tag and attribute allowlist, as passed to `sanitize(html, tags:, attributes:)`.
 *
 * Every sanitization layer in the pipeline is Rails' PermitScrubber with one of these lists
 * (reference/app/helpers/content_filters.rb lists the three layers).
 */
final readonly class SafeList
{
    /** `Rails::HTML::Concern::Scrubber::SafeList::DEFAULT_ALLOWED_TAGS` */
    public const array DEFAULT_ALLOWED_TAGS = [
        'a', 'abbr', 'acronym', 'address', 'b', 'big', 'blockquote', 'br', 'cite', 'code', 'dd', 'del', 'dfn', 'div', 'dl', 'dt', 'em', 'h1',
        'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'img', 'ins', 'kbd', 'li', 'mark', 'ol', 'p', 'pre', 'samp', 'small', 'span', 'strong', 'sub',
        'sup', 'time', 'tt', 'ul', 'var',
    ];

    /**
     * `Rails::HTML::Concern::Scrubber::SafeList::DEFAULT_ALLOWED_ATTRIBUTES` without `name`, which
     * would let a message clobber the page's DOM globals (`<img name="body">` shadows
     * `document.body`). A deliberate difference, as in the Rust port; nothing the composer writes
     * has one.
     */
    public const array DEFAULT_ALLOWED_ATTRIBUTES = ['abbr', 'alt', 'cite', 'class', 'datetime', 'height', 'href', 'lang', 'src', 'title', 'width', 'xml:lang'];

    /** `ContentFilters::EDITOR_FORMATTING_TAGS` (reference/app/helpers/content_filters.rb) */
    public const array EDITOR_FORMATTING_TAGS = ['s', 'u', 'mark', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td'];

    /** `ContentFilters::EDITOR_FORMATTING_ATTRIBUTES` */
    public const array EDITOR_FORMATTING_ATTRIBUTES = ['data-language'];

    /** `ActionText::Attachment::ATTRIBUTES` */
    public const array ATTACHMENT_ATTRIBUTES = ['sgid', 'content-type', 'url', 'href', 'filename', 'filesize', 'width', 'height', 'previewable', 'presentation', 'caption', 'content'];

    /** `ContentFilters::SanitizeTags::ALLOWED_TAGS` (reference/app/helpers/content_filters/sanitize_tags.rb) */
    public const array SANITIZE_TAGS_ALLOWED_TAGS = [
        'a', 'abbr', 'acronym', 'address', 'b', 'big', 'blockquote', 'br', 'cite', 'code', 'dd', 'del', 'dfn', 'div', 'dl', 'dt', 'em',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'ins', 'kbd', 'li', 'ol', 'p', 'pre', 'samp', 'small', 'span', 'strong', 'sub',
        'sup', 'time', 'tt', 'ul', 'var',
        's', 'u', 'mark', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
        'action-text-attachment', 'figure', 'figcaption',
    ];

    /** @var array<string, true> */
    public array $tags;
    /** @var array<string, true> */
    public array $attributes;

    /**
     * @param list<string> $tags
     * @param list<string> $attributes
     */
    public function __construct(array $tags, array $attributes)
    {
        $this->tags = array_fill_keys($tags, true);
        $this->attributes = array_fill_keys($attributes, true);
    }

    /** Action View's `sanitize(html)` with no options: the sanitizer's class-level defaults. */
    public static function defaults(): self
    {
        static $list;

        return $list ??= new self(self::DEFAULT_ALLOWED_TAGS, self::DEFAULT_ALLOWED_ATTRIBUTES);
    }

    /**
     * `ActionText::ContentHelper.allowed_tags`/`allowed_attributes` as configured at boot: Action
     * Text's defaults, then Lexxy's additions (lexxy/engine.rb, "lexxy.sanitization"), then
     * Campfire's (reference/lib/rails_ext/action_text_allowed_tags.rb).
     */
    public static function actionText(): self
    {
        static $list;

        return $list ??= new self(
            [...self::DEFAULT_ALLOWED_TAGS, 'action-text-attachment', 'figure', 'figcaption', 'video', 'audio', 'source', 'embed', 'table', 'tbody', 'tr', 'th', 'td', ...self::EDITOR_FORMATTING_TAGS],
            [...self::DEFAULT_ALLOWED_ATTRIBUTES, ...self::ATTACHMENT_ATTRIBUTES, 'controls', 'poster', 'data-language', 'style', 'value', 'start', ...self::EDITOR_FORMATTING_ATTRIBUTES],
        );
    }

    /** `ContentFilters::SanitizeAttributes`: SanitizeTags' tags, Action Text's attributes plus `class`. */
    public static function contentFilter(): self
    {
        static $list;

        return $list ??= new self(self::SANITIZE_TAGS_ALLOWED_TAGS, [...array_keys(self::actionText()->attributes), 'class']);
    }

    /** `MessagesHelper::AUTO_LINK_ALLOWED_TAGS`/`AUTO_LINK_ALLOWED_ATTRIBUTES` (reference/app/helpers/messages_helper.rb). */
    public static function autoLink(): self
    {
        static $list;

        return $list ??= new self([...self::DEFAULT_ALLOWED_TAGS, ...self::EDITOR_FORMATTING_TAGS], [...self::DEFAULT_ALLOWED_ATTRIBUTES, ...self::EDITOR_FORMATTING_ATTRIBUTES]);
    }

    public function allowsTag(string $name): bool
    {
        return isset($this->tags[$name]);
    }

    public function allowsAttribute(string $name): bool
    {
        return isset($this->attributes[$name]);
    }
}
