<?php

declare(strict_types=1);

namespace App\RichText;

use App\Rails\RailsJson;
use App\RichText\Attachables\Attachment;
use App\RichText\Attachables\Attachments;
use App\RichText\Attachables\MentionUser;
use App\RichText\Html\Html;
use App\RichText\Sanitizer\SafeList;
use App\RichText\Sanitizer\SafeListSanitizer;
use Dom\Element;
use Dom\Text;

/**
 * `ActionText::Content`: loading (canonicalization), attachment rendering and plain text.
 *
 * Each Ruby step that serializes a node and parses the markup back (`Fragment#replace` with a
 * string, `inner_html=`, a filter returning HTML) does the same here, in the same parse context,
 * because those round trips are where the output takes its shape.
 */
final class Content
{
    /**
     * How deep content attachments render inside one another. Each level parses and sanitizes
     * everything nested below it again, so Rails' unbounded nesting makes rendering quadratic in
     * the body's size; deeper content attachments render empty (a deliberate difference, as in
     * the Rust port). Nothing Campfire's composer makes nests them at all.
     */
    public const int MAX_CONTENT_ATTACHMENT_DEPTH = 8;

    /** `ActionText::TrixAttachment::ATTRIBUTES` by their dashed `ActionText::Attachment::ATTRIBUTES` names */
    private const array TRIX_ATTRIBUTES = [
        'sgid' => 'sgid', 'content-type' => 'contentType', 'url' => 'url', 'href' => 'href', 'filename' => 'filename',
        'filesize' => 'filesize', 'width' => 'width', 'height' => 'height', 'previewable' => 'previewable',
        'presentation' => 'presentation', 'caption' => 'caption', 'content' => 'content',
    ];

    private function __construct(public readonly Element $fragment)
    {
    }

    /**
     * `ActionText::Content.new(html)`, which is also how a stored body loads: canonicalized
     * (Trix attachments converted, attachments minified, galleries canonicalized).
     *
     * @throws RichTextError
     */
    public static function load(string $html, RenderContext $context): self
    {
        $fragment = Html::fragment(Ruby::strip($html));
        self::convertTrixAttachments($fragment, $context);
        foreach (Html::elementsNamed($fragment, Attachments::TAG_NAME) as $node) {
            while (null !== $node->firstChild) {
                $node->removeChild($node->firstChild);
            }
        }
        foreach (self::attachmentGalleryNodes($fragment) as $gallery) {
            Html::replaceWithHtml($gallery, '<div>'.Html::innerHtml($gallery).'</div>');
        }

        return new self($fragment);
    }

    /**
     * `ActionText::Content.new(html, canonicalize: false)`, i.e. `Fragment.from_html`.
     *
     * @throws RichTextError
     */
    public static function wrap(string $html): self
    {
        return new self(Html::fragment(Ruby::strip($html)));
    }

    public static function fromFragment(Element $fragment): self
    {
        return new self($fragment);
    }

    public function toHtml(): string
    {
        return Html::toHtml($this->fragment);
    }

    public function copy(): self
    {
        $copy = $this->fragment->cloneNode(true);
        \assert($copy instanceof Element);

        return new self($copy);
    }

    /**
     * `ActionText::Content#to_plain_text`.
     *
     * @throws RichTextError
     */
    public function toPlainText(RenderContext $context): string
    {
        $fragment = $this->copy()->fragment;
        foreach (Html::elementsNamed($fragment, Attachments::TAG_NAME) as $node) {
            self::sanitizeContentAttribute($node);
            [$kind, $text] = Attachments::plainText(Attachments::fromNode($node, $context));
            if ('content' === $kind) {
                // ContentAttachment#attachable_plain_text_representation: the canonicalized fragment
                $text = self::load($text, $context)->toHtml();
            }
            Html::replaceWithHtml($node, $text);
        }

        return PlainTextConversion::nodeToPlainText($fragment);
    }

    /**
     * `render_action_text_content(content)`: attachments and galleries rendered, then sanitized
     * with Action Text's allowlist.
     *
     * @throws RichTextError
     */
    public function render(RenderContext $context, int $depth = 0): string
    {
        $fragment = $this->copy()->fragment;
        foreach (Html::elementsNamed($fragment, Attachments::TAG_NAME) as $node) {
            self::sanitizeContentAttribute($node);
            $attachment = Attachments::fromNode($node, $context);
            $full = self::nodeWithFullAttributes($node, $attachment);
            $attachment = new Attachment($attachment->attachable, Ruby::presence($full->getAttribute('caption')));
            Html::setInnerHtml($full, self::renderAttachment($attachment, $context, $depth));
            Html::replaceWithHtml($node, Html::toHtml($full));
        }
        foreach (self::attachmentGalleryNodes($fragment) as $gallery) {
            $members = array_values(array_filter(Html::elementsNamed($gallery, Attachments::TAG_NAME), self::isGalleryAttachment(...)));
            $rendered = '';
            foreach ($members as $member) {
                $attachment = Attachments::fromNode($member, $context);
                $full = self::nodeWithFullAttributes($member, $attachment);
                Html::setInnerHtml($full, self::renderAttachment($attachment, $context, $depth));
                $rendered .= Html::toHtml($full);
            }
            Html::replaceWithHtml($gallery, '<div class="attachment-gallery attachment-gallery--'.\count($members)."\">\n  ".$rendered."\n</div>");
        }

        return SafeListSanitizer::sanitize(Html::toHtml($fragment), SafeList::actionText());
    }

    /**
     * `Content#to_s`: the content partial inside `layouts/action_text/contents/_content.html.erb`,
     * which Campfire overrides with a `lexxy-content` wrapper.
     *
     * @throws RichTextError
     */
    public function toRenderedHtmlWithLayout(RenderContext $context): string
    {
        return "<div class=\"lexxy-content\">\n  ".$this->render($context)."\n</div>\n";
    }

    /**
     * `render_action_text_attachment`, with nested content attachments rendered through
     * `ContentAttachment#to_html` (the content partial, without the layout).
     *
     * @throws RichTextError
     */
    public static function renderAttachment(Attachment $attachment, RenderContext $context, int $depth = 0): string
    {
        return Attachments::render($attachment, static function (string $content) use ($context, $depth): string {
            if ($depth >= self::MAX_CONTENT_ATTACHMENT_DEPTH) {
                return '';
            }

            return self::load($content, $context)->render($context, $depth + 1)."\n";
        });
    }

    /**
     * RichTextHelper#editable_body (reference/app/helpers/rich_text_helper.rb), then Lexxy's
     * `render_custom_attachments_in`: the value of the `<lexxy-editor>` editing a message. Null
     * when the body is blank, where the editor gets no value attribute at all.
     *
     * @throws RichTextError
     */
    public static function editableValue(string $storedBody, RenderContext $context): ?string
    {
        // editable_body: every attachment rebuilt from its attachable, on the stored markup as is
        $fragment = Html::fragment(Ruby::strip($storedBody));
        foreach (Html::elementsNamed($fragment, Attachments::TAG_NAME) as $node) {
            $attachment = Attachments::fromNode($node, $context);
            // A mention of a deleted user, say: nothing to edit, so it leaves the editor, where
            // Rails raises (a deliberate difference, as in the Rust port)
            if ($attachment->attachable instanceof Attachables\MissingAttachable) {
                $node->parentNode?->removeChild($node);
                continue;
            }
            $contentType = Attachments::attachableContentType($attachment);
            $content = self::renderAttachment($attachment, $context);
            $node->setAttribute('content-type', $contentType);
            $node->setAttribute('content', $content);
        }
        $editable = Html::toHtml($fragment);
        if (Ruby::isBlank($editable)) {
            return null;
        }

        // Lexxy: attachments without a url get their rendered partial as a JSON string
        $fragment = Html::fragment(Ruby::strip($editable));
        foreach (Html::elementsNamed($fragment, Attachments::TAG_NAME) as $node) {
            if (Ruby::isBlank($node->getAttribute('url'))) {
                $attachment = Attachments::fromNode($node, $context);
                $node->setAttribute('content', RailsJson::encode(self::renderAttachment($attachment, $context)));
            }
        }

        return Html::toHtml($fragment);
    }

    /**
     * `Message::Mentionee#mentioned_users`: users attached with a verified SGID, once each, in
     * order of appearance (`body.body.attachables.grep(User).uniq`).
     *
     * @return list<MentionUser>
     *
     * @throws RichTextError
     */
    public function mentionedUsers(RenderContext $context): array
    {
        $users = [];
        foreach (Html::elementsNamed($this->fragment, Attachments::TAG_NAME) as $node) {
            $attachable = Attachments::actionTextAttachableFromNode($node, $context);
            if ($attachable instanceof MentionUser && !isset($users[$attachable->id])) {
                $users[$attachable->id] = $attachable;
            }
        }

        return array_values($users);
    }

    /** @return list<Element> */
    public function attachmentNodes(): array
    {
        return Html::elementsNamed($this->fragment, Attachments::TAG_NAME);
    }

    // --- Trix attachments --------------------------------------------------------------------

    /**
     * `fragment_by_converting_trix_attachments`: any element carrying `data-trix-attachment`
     * becomes an `<action-text-attachment>`, or disappears if it has none of the attachment
     * attributes.
     *
     * @throws RichTextError
     */
    private static function convertTrixAttachments(Element $fragment, RenderContext $context): void
    {
        $nodes = array_values(array_filter(Html::descendants($fragment), static fn ($n): bool => $n instanceof Element && $n->hasAttribute('data-trix-attachment')));
        foreach ($nodes as $node) {
            \assert($node instanceof Element);
            /** @var array<string, mixed> $attributes trix name => value */
            $attributes = [];
            foreach (['data-trix-attachment', 'data-trix-attributes'] as $name) {
                $json = $node->getAttribute($name);
                $parsed = null;
                if (null !== $json) {
                    try {
                        $parsed = Ruby::jsonParse($json);
                    } catch (\JsonException) {
                        // Logged, and treated as no attributes
                        $parsed = null;
                    }
                }
                if (null === $parsed || false === $parsed) {
                    continue;
                }
                if (!$parsed instanceof \stdClass) {
                    throw new RichTextError('NoMethodError: merge');
                }
                foreach (get_object_vars($parsed) as $key => $value) {
                    if (\in_array($key, self::TRIX_ATTRIBUTES, true)) {
                        $attributes[$key] = $value;
                    }
                }
            }
            $elementAttributes = [];
            foreach (self::TRIX_ATTRIBUTES as $dashed => $trix) {
                if (\array_key_exists($trix, $attributes)) {
                    $elementAttributes[$dashed] = Ruby::jsonValueToS($attributes[$trix]);
                }
            }
            if ([] === $elementAttributes) {
                $replacement = '';
            } else {
                $element = Html::createElement($fragment, Attachments::TAG_NAME, $elementAttributes);
                // Attachment.from_node resolves the attachable, which may raise
                Attachments::fromNode($element, $context);
                $replacement = Html::toHtml($element);
            }
            Html::replaceWithHtml($node, $replacement);
        }
    }

    // --- Rendering ---------------------------------------------------------------------------

    /**
     * `render_attachments`' first step: an attachment's `content` attribute is sanitized with
     * Action Text's allowlist, and dropped if that leaves nothing.
     *
     * @throws RichTextError
     */
    private static function sanitizeContentAttribute(Element $node): void
    {
        $content = $node->getAttribute('content');
        if (null === $content) {
            return;
        }
        $node->removeAttribute('content');
        $sanitized = SafeListSanitizer::sanitize($content, SafeList::actionText());
        if (!Ruby::isBlank($sanitized)) {
            $node->setAttribute('content', $sanitized);
        }
    }

    /**
     * `Attachment#with_full_attributes`: a new node carrying the node's attachment attributes,
     * the attachable's own (sgid and content type, for a user), and the node's sgid if it had one.
     *
     * @throws RichTextError
     */
    private static function nodeWithFullAttributes(Element $node, Attachment $attachment): Element
    {
        $user = $attachment->attachable instanceof MentionUser ? $attachment->attachable : null;
        $attributes = [];
        foreach (SafeList::ATTACHMENT_ATTRIBUTES as $name) {
            $value = match (true) {
                'sgid' === $name && null !== $user => $node->getAttribute('sgid') ?? $user->attachableSgid,
                'content-type' === $name && null !== $user => Attachments::MENTION_CONTENT_TYPE,
                default => $node->getAttribute($name),
            };
            if (null !== $value) {
                $attributes[$name] = $value;
            }
        }
        if ([] === $attributes) {
            // from_attributes returns nil, and the render block calls #node on it
            throw new RichTextError('NoMethodError: node for nil');
        }

        return Html::createElement($node, Attachments::TAG_NAME, $attributes);
    }

    private static function isGalleryAttachment(mixed $node): bool
    {
        return $node instanceof Element && Attachments::TAG_NAME === $node->localName && 'gallery' === $node->getAttribute('presentation');
    }

    /**
     * `AttachmentGallery.find_attachment_gallery_nodes`: `div:has(A + A)` for gallery
     * attachments A, whose children are all gallery attachments or newline/space text.
     *
     * @return list<Element>
     */
    private static function attachmentGalleryNodes(Element $fragment): array
    {
        $galleries = [];
        foreach (Html::elementsNamed($fragment, 'div') as $div) {
            $hasPair = false;
            foreach (Html::descendants($div) as $node) {
                if ($node instanceof Element && self::isGalleryAttachment($node)) {
                    $previous = $node->previousElementSibling;
                    if (null !== $previous && self::isGalleryAttachment($previous)) {
                        $hasPair = true;
                        break;
                    }
                }
            }
            if (!$hasPair) {
                continue;
            }
            foreach (Html::children($div) as $child) {
                if ($child instanceof Text ? 1 !== preg_match('/\A[\n ]*\z/', $child->data) : !self::isGalleryAttachment($child)) {
                    continue 2;
                }
            }
            $galleries[] = $div;
        }

        return $galleries;
    }
}
