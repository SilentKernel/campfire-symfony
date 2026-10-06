<?php

declare(strict_types=1);

namespace App\RichText\Filters;

use App\RichText\Attachables\Attachments;
use App\RichText\Attachables\OpengraphEmbed;
use App\RichText\Content;
use App\RichText\Html\Html;
use App\RichText\RenderContext;
use App\RichText\RichTextError;
use App\RichText\Ruby;
use App\RichText\RubyUri;
use App\RichText\Sanitizer\SafeList;
use App\RichText\Sanitizer\SafeListSanitizer;
use Dom\Element;

/**
 * `ContentFilters::TextMessagePresentationFilters` (reference/app/helpers/content_filters.rb):
 * RemoveSoloUnfurledLinkText, SanitizeTags, SanitizeAttributes, applied in that order
 * (reference/lib/rails_ext/filter{,s}.rb).
 */
final class TextMessagePresentationFilters
{
    private const array TWITTER_DOMAINS = ['x.com', 'twitter.com'];

    /** @throws RichTextError */
    public static function apply(Content $content, RenderContext $context): Content
    {
        return self::sanitizeAttributes(self::sanitizeTags(self::removeSoloUnfurledLinkText($content, $context)));
    }

    /**
     * reference/app/helpers/content_filters/remove_solo_unfurled_link_text.rb: a message that is
     * nothing but a link to what it unfurls shows just the unfurl.
     *
     * @throws RichTextError
     */
    public static function removeSoloUnfurledLinkText(Content $content, RenderContext $context): Content
    {
        $unfurledLinks = array_values(array_filter(
            Html::elementsNamed($content->fragment, Attachments::TAG_NAME),
            static fn (Element $node): bool => OpengraphEmbed::CONTENT_TYPE === $node->getAttribute('content-type'),
        ));
        $soloUnfurledUrl = 1 === \count($unfurledLinks) ? Attachments::opengraphEmbedFromNode($unfurledLinks[0], $context)?->href : null;
        $applicable = self::normalizeTweetUrl($soloUnfurledUrl) === self::normalizeTweetUrl($content->toPlainText($context));
        if (!$applicable) {
            return $content;
        }

        $result = $content->copy();
        $fragment = $result->fragment;
        if ([] !== Html::elementsNamed($fragment, 'div')) {
            // Every div gets the unfurl as its only content
            $unfurl = Html::toHtml($unfurledLinks[0]);
            foreach (Html::elementsNamed($fragment, 'div') as $div) {
                Html::setInnerHtml($div, $unfurl);
            }
        } else {
            foreach (Html::elementsNamed($fragment, 'p') as $p) {
                if ([] === Html::elementsNamed($p, Attachments::TAG_NAME)) {
                    $p->parentNode?->removeChild($p);
                }
            }
        }

        return $result;
    }

    /** @throws RichTextError */
    private static function normalizeTweetUrl(?string $url): ?string
    {
        if (null === $url) {
            return null;
        }
        $twitter = false;
        if (!Ruby::isBlank($url)) {
            foreach (self::TWITTER_DOMAINS as $domain) {
                $twitter = $twitter || str_contains(Ruby::strip($url), $domain);
            }
        }
        if (!$twitter) {
            return $url;
        }
        $parsed = RubyUri::parse($url);
        if (null === $parsed) {
            return $url;
        }
        if (null !== $parsed->host && 'x.com' === strtolower($parsed->host)) {
            $parsed->host = 'twitter.com';
        }
        $parsed->query = null;

        return $parsed->toS();
    }

    /**
     * reference/app/helpers/content_filters/sanitize_tags.rb: removes every element outside the
     * allowlist, together with its contents.
     */
    public static function sanitizeTags(Content $content): Content
    {
        $result = $content->copy();
        foreach (Html::descendants($result->fragment) as $node) {
            if ($node instanceof Element && !\in_array($node->localName, SafeList::SANITIZE_TAGS_ALLOWED_TAGS, true)) {
                $node->parentNode?->removeChild($node);
            }
        }

        return $result;
    }

    /**
     * reference/app/helpers/content_filters/sanitize_attributes.rb: scrubs attributes with Rails'
     * safe-list sanitizer over SanitizeTags' own tags.
     *
     * @throws RichTextError
     */
    public static function sanitizeAttributes(Content $content): Content
    {
        return Content::wrap(SafeListSanitizer::sanitize($content->toHtml(), SafeList::contentFilter()));
    }
}
