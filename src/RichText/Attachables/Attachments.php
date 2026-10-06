<?php

declare(strict_types=1);

namespace App\RichText\Attachables;

use App\RichText\Html\Html;
use App\RichText\RenderContext;
use App\RichText\RichTextError;
use App\RichText\Ruby;
use App\RichText\RubyUri;
use Dom\Element;

/**
 * Resolving `<action-text-attachment>` nodes to what they attach, and rendering each attachable's
 * partial, as Action Text, Lexxy and Campfire's extensions do.
 */
final class Attachments
{
    public const string TAG_NAME = 'action-text-attachment';
    /** User::Mentionable::MENTION_CONTENT_TYPE (reference/app/models/user/mentionable.rb) */
    public const string MENTION_CONTENT_TYPE = 'application/vnd.campfire.mention';

    // --- Resolution ------------------------------------------------------------------------

    /**
     * Campfire's `ActionText::Attachment.from_node` (reference/lib/rails_ext/action_text_attachables.rb):
     * an opengraph embed, else a User found through a possibly invalid SGID, else Action Text's
     * own lookup (as extended by Lexxy).
     *
     * @throws RichTextError
     */
    public static function fromNode(Element $node, RenderContext $context): Attachment
    {
        $attachable = self::opengraphEmbedFromNode($node, $context)
            ?? $context->locator->userFromPossiblyExpiredSgid($node->getAttribute('sgid'))
            ?? self::actionTextAttachableFromNode($node, $context);

        return new Attachment($attachable, Ruby::presence($node->getAttribute('caption')));
    }

    /**
     * `ActionText::Attachable.from_node`, with Lexxy's RemoteVideo fallback for missing
     * attachables. `Content#attachables` (and so `Message#mentionees`) uses this directly,
     * without Campfire's invalid-signature fallback.
     */
    public static function actionTextAttachableFromNode(Element $node, RenderContext $context): MentionUser|ContentAttachment|RemoteImage|RemoteVideo|MissingAttachable
    {
        $sgid = $node->getAttribute('sgid');
        $signed = null !== $sgid ? $context->locator->locateSigned($sgid) : new SignedLookup();
        if (null !== $signed->user) {
            return $signed->user;
        }
        $contentType = $node->getAttribute('content-type');
        $content = $node->getAttribute('content');
        if (null !== $content && null !== $contentType && str_contains($contentType, 'html') && !Ruby::isBlank($content)) {
            return new ContentAttachment($content);
        }
        $url = $node->getAttribute('url');
        if (null !== $url) {
            if (1 === preg_match('~^image(/.+|$)~m', $contentType ?? '')) {
                return new RemoteImage($url, $node->getAttribute('width'), $node->getAttribute('height'));
            }
            if (1 === preg_match('~^video(/.+|$)~m', $contentType ?? '')) {
                return new RemoteVideo($url, (string) $contentType, $node->getAttribute('width'), $node->getAttribute('height'), $node->getAttribute('filename'));
            }
        }

        return new MissingAttachable($signed->missingModel);
    }

    /**
     * `ActionText::Attachment::OpengraphEmbed.from_node`.
     *
     * @throws RichTextError
     */
    public static function opengraphEmbedFromNode(Element $node, RenderContext $context): ?OpengraphEmbed
    {
        $contentType = $node->getAttribute('content-type');
        if (null === $contentType || 1 !== preg_match('~application/vnd.actiontext.opengraph-embed~', $contentType)) {
            return null;
        }
        if (null !== Ruby::presence($node->getAttribute('filename'))) {
            return new OpengraphEmbed(
                self::webUrl($node->getAttribute('href'), $context->requestHost),
                self::webUrl($node->getAttribute('url'), $context->requestHost),
                $node->getAttribute('filename'),
                $node->getAttribute('caption'),
            );
        }

        return self::embedFromContent($node->getAttribute('content') ?? '', $context->requestHost);
    }

    /**
     * `attributes_from_content`: the details Lexxy serializes as the embed's content markup.
     * Rails parses it with Nokogiri::HTML (libxml2's HTML4 parser), which agrees with the HTML5
     * parser on the markup the embed partial and Lexxy produce.
     *
     * @throws RichTextError
     */
    private static function embedFromContent(string $content, string $host): OpengraphEmbed
    {
        $fragment = Html::fragment($content);
        $title = null;
        $description = null;
        $image = null;
        foreach (Html::descendants($fragment) as $node) {
            if (!$node instanceof Element) {
                continue;
            }
            if (null === $title && self::hasClass($node, 'og-embed__title')) {
                $title = $node;
            }
            if (null === $description && self::hasClass($node, 'og-embed__description')) {
                $description = $node;
            }
            if (null === $image && 'img' === $node->localName && self::hasAncestorWithClass($node, 'og-embed__image')) {
                $image = $node;
            }
        }
        $link = null !== $title ? (Html::elementsNamed($title, 'a')[0] ?? null) : null;
        $named = $link ?? $title;

        return new OpengraphEmbed(
            self::webUrl($link?->getAttribute('href'), $host),
            self::webUrl($image?->getAttribute('src'), $host),
            null !== $named ? Ruby::strip(Html::textContent($named)) : null,
            null !== $description ? Ruby::strip(Html::textContent($description)) : null,
        );
    }

    private static function hasClass(Element $node, string $class): bool
    {
        $classes = $node->getAttribute('class');

        return null !== $classes && \in_array($class, preg_split('/[ \t\n\r\f]+/', $classes) ?: [], true);
    }

    private static function hasAncestorWithClass(Element $node, string $class): bool
    {
        for ($parent = $node->parentElement; null !== $parent; $parent = $parent->parentElement) {
            if (self::hasClass($parent, $class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `web_url`: an absolute http(s) URL on a named host other than this Campfire's.
     *
     * @throws RichTextError
     */
    public static function webUrl(?string $value, string $requestHost): ?string
    {
        if (Ruby::isBlank($value)) {
            return null;
        }
        \assert(null !== $value);
        $parsed = RubyUri::parse($value);
        if (null === $parsed || !$parsed->isHttp() || !self::elsewhere($parsed->host, $requestHost)) {
            return null;
        }

        return $value;
    }

    /** @throws RichTextError */
    private static function elsewhere(?string $host, string $requestHost): bool
    {
        if (null === $host || !self::namedHost($host)) {
            return false;
        }

        return self::canonicalHost($host) !== self::canonicalHost($requestHost);
    }

    /** @throws RichTextError */
    private static function namedHost(string $host): bool
    {
        if (Ruby::isBlank($host) || str_contains($host, '%') || !str_contains($host, '.')) {
            return false;
        }
        // `host.split(".").last`: Ruby drops trailing empty labels, and nil.match? raises
        $trimmed = rtrim($host, '.');
        if ('' === $trimmed) {
            throw new RichTextError('NoMethodError: match?');
        }
        $labels = explode('.', $trimmed);
        $label = end($labels);

        return 1 === preg_match('/[a-z]/i', $label) && 1 !== preg_match('/\A0x/i', $label);
    }

    private static function canonicalHost(string $host): string
    {
        $lower = mb_strtolower($host, 'UTF-8');

        return str_ends_with($lower, '.') ? substr($lower, 0, -1) : $lower;
    }

    /** `attachable_content_type`, which only some attachables define. */
    public static function attachableContentType(Attachment $attachment): string
    {
        return match (true) {
            $attachment->attachable instanceof MentionUser => self::MENTION_CONTENT_TYPE,
            $attachment->attachable instanceof OpengraphEmbed => OpengraphEmbed::CONTENT_TYPE,
            default => throw new RichTextError('NoMethodError: attachable_content_type'),
        };
    }

    // --- Partials ----------------------------------------------------------------------------

    /**
     * `render_action_text_attachment(attachment)`: the attachable's partial, chomped.
     * `$renderContent` renders a content attachment's own content (`ContentAttachment#to_html`).
     *
     * @param callable(string): string $renderContent
     *
     * @throws RichTextError
     */
    public static function render(Attachment $attachment, callable $renderContent): string
    {
        $attachable = $attachment->attachable;
        $html = match (true) {
            $attachable instanceof MentionUser => self::renderMention($attachable),
            $attachable instanceof OpengraphEmbed => self::renderOpengraphEmbed($attachable),
            // Rails asks the SGID's model for its missing partial, which only models that include
            // ActionText::Attachable as a concern have. User doesn't, so a mention of a deleted
            // user raised and blanked the whole message; every missing attachable is Action
            // Text's ☒ here (a deliberate difference, as in the Rust port).
            $attachable instanceof MissingAttachable => '☒',
            $attachable instanceof ContentAttachment => "<figure class=\"attachment attachment--content\">\n  ".$renderContent($attachable->content)."\n</figure>\n",
            $attachable instanceof RemoteImage => "<figure class=\"attachment attachment--preview\">\n  ".self::imageTag($attachable->url, $attachable->width, $attachable->height)."\n"
                .self::caption($attachment->caption)."</figure>\n",
            $attachable instanceof RemoteVideo => "<figure class=\"attachment attachment--preview attachment--video\">\n  <video controls=\"controls\""
                .(null !== $attachable->width ? ' width="'.Ruby::h($attachable->width).'"' : '')
                .(null !== $attachable->height ? ' height="'.Ruby::h($attachable->height).'"' : '')
                .">\n    <source src=\"".Ruby::h($attachable->url).'" type="'.Ruby::h($attachable->contentType)."\">\n</video>"
                .self::caption($attachment->caption)."</figure>\n",
        };

        return Ruby::chomp($html);
    }

    private static function caption(?string $caption): string
    {
        return null === $caption ? '' : "    <figcaption class=\"attachment__caption\">\n      ".Ruby::h($caption)."\n    </figcaption>\n";
    }

    /** reference/app/views/users/_mention.html.erb (templates/users/_mention.html.twig) */
    public static function renderMention(MentionUser $user): string
    {
        return '<span class="mention" sgid="'.Ruby::h($user->attachableSgid).'">'.$user->avatarTag.' '.Ruby::h($user->name)."</span>\n";
    }

    /** reference/app/views/action_text/attachables/_opengraph_embed.html.erb */
    public static function renderOpengraphEmbed(OpengraphEmbed $embed): string
    {
        $filename = null !== $embed->filename ? Ruby::h(Ruby::truncate($embed->filename, 280, '…')) : null;
        $title = match (true) {
            null !== $embed->href => '<a rel="noreferrer" target="_blank" href="'.Ruby::h($embed->href).'">'.($filename ?? Ruby::h($embed->href)).'</a>',
            default => $filename ?? '',
        };
        $html = "<figure class=\"attachment attachment--content attachment--og\">\n  <actiontext-opengraph-embed>\n"
            .'    <div class="og-embed gap '.($embed->isTwitterAvatar() ? 'og-embed--twitter-avatar' : '')."\">\n"
            ."      <div class=\"og-embed__content\">\n        <div class=\"og-embed__title\">\n          ".$title."\n        </div>\n"
            .'        <div class="og-embed__description">'.Ruby::h(Ruby::truncate($embed->description ?? '', 560, '…'))."</div>\n      </div>\n";
        if (null !== $embed->url) {
            $html .= "        <div class=\"og-embed__image\">\n          <img src=\"".Ruby::h($embed->url)."\" class=\"image center\" alt=\"\">\n        </div>\n";
        }

        return $html."    </div>\n  </actiontext-opengraph-embed>\n</figure>\n";
    }

    /**
     * `image_tag(url, width:, height:)` for a remote image. Sources that aren't URLs go through
     * the asset pipeline, which raises for anything it doesn't know; a rooted path passes through.
     *
     * @throws RichTextError
     */
    private static function imageTag(string $url, ?string $width, ?string $height): string
    {
        if (Ruby::isBlank($url)) {
            $source = '';
        } elseif (1 === preg_match('~^[-a-z]+://|^(?:cid|data):|^//~mi', $url) || str_starts_with($url, '/')) {
            $source = $url;
        } else {
            throw new RichTextError('Propshaft::MissingAssetError');
        }
        $html = '<img';
        if (null !== $width) {
            $html .= ' width="'.Ruby::h($width).'"';
        }
        if (null !== $height) {
            $html .= ' height="'.Ruby::h($height).'"';
        }

        return $html.' src="'.Ruby::h($source).'" />';
    }

    /**
     * `Attachment#to_plain_text`: a string replaces the node as markup parsed in its parent's
     * context, while a content attachment's (canonicalized) fragment is moved in as is.
     *
     * @return array{0: 'html'|'content', 1: string}
     */
    public static function plainText(Attachment $attachment): array
    {
        $attachable = $attachment->attachable;
        $caption = $attachment->caption;

        return match (true) {
            $attachable instanceof MentionUser => ['html', '@'.$attachable->name],
            $attachable instanceof OpengraphEmbed => ['html', ''],
            $attachable instanceof ContentAttachment => ['content', $attachable->content],
            $attachable instanceof RemoteImage => ['html', '['.($caption ?? 'Image').']'],
            $attachable instanceof RemoteVideo => ['html', '['.($caption ?? $attachable->filename ?? 'Video').']'],
            $attachable instanceof MissingAttachable => ['html', $caption ?? ''],
        };
    }
}
