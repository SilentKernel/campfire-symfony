<?php

declare(strict_types=1);

namespace App\Domain\Messages;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * The Action Text operations messages need, from App\RichText (the rich text workstream):
 *
 * - RichTextRenderer::render(?string): string, `message_presentation` for a text message (the
 *   body through the presentation filters, rendered in its layout, auto-linked);
 * - RichTextRenderer::toPlainText(?string): string, `body.to_plain_text`;
 * - RichTextRenderer::forEditor(?string): string, `editable_body(message)`'s value;
 * - Canonicalizer::canonicalize(string): string, what assigning a String to a rich text stores;
 * - MentionExtractor::userIds(?string): list<int>, the mentioned users (`attachables.grep(User)`).
 *
 * The services are looked up optionally so the application still boots while App\RichText is
 * incomplete; until they exist, minimal stand-ins keep messages working (documented in the
 * workstream report, not used once the real services are registered).
 */
final readonly class MessageRichText
{
    public function __construct(
        #[AutowireLocator([
            'renderer' => '?App\RichText\RichTextRenderer',
            'canonicalizer' => '?App\RichText\Canonicalizer',
            'mentions' => '?App\RichText\MentionExtractor',
        ])]
        private ContainerInterface $services,
    ) {
    }

    /** `message_presentation(message)` for a text message. */
    public function render(?string $body): string
    {
        if ($this->services->has('renderer')) {
            return $this->services->get('renderer')->render($body);
        }

        return "<div class=\"lexxy-content\">\n  ".($body ?? '')."\n</div>\n";
    }

    /** `body.to_plain_text` */
    public function toPlainText(?string $body): string
    {
        if ($this->services->has('renderer')) {
            return $this->services->get('renderer')->toPlainText($body);
        }

        return trim(html_entity_decode(strip_tags((string) $body), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }

    /** `editable_body(message)`: the body as the editor's value. */
    public function forEditor(?string $body): string
    {
        if ($this->services->has('renderer')) {
            return $this->services->get('renderer')->forEditor($body);
        }

        return (string) $body;
    }

    /** `ActionText::Content.new(body, canonicalize: true).to_html` */
    public function canonicalize(string $body): string
    {
        if ($this->services->has('canonicalizer')) {
            return $this->services->get('canonicalizer')->canonicalize($body);
        }

        return $body;
    }

    /**
     * The ids of the users the body mentions.
     *
     * @return list<int>
     */
    public function mentionedUserIds(?string $body): array
    {
        if ($this->services->has('mentions')) {
            return $this->services->get('mentions')->userIds($body);
        }

        return [];
    }
}
