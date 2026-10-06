<?php

declare(strict_types=1);

namespace App\RichText;

use App\Http\Current;
use App\RichText\Attachables\AttachableLocator;
use App\RichText\Filters\TextMessagePresentationFilters;
use App\RichText\Sanitizer\SafeList;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * How Campfire shows a message's rich text body (the `action_text_rich_texts.body` column, as
 * stored), as Action Text with Lexxy and Campfire's extensions renders it.
 */
final readonly class RichTextRenderer
{
    public function __construct(
        private AttachableLocator $locator,
        private Current $current,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * The text branch of `MessagesHelper#message_presentation` (reference/app/helpers/messages_helper.rb),
     * which messages/_presentation.html.erb puts inside its `div#presentation_…`:
     *
     *     auto_link h(TextMessagePresentationFilters.apply(message.body.body)), html: { target: "_blank" }, sanitize_options: …
     *
     * i.e. the content filters, the body rendered with attachments inside the
     * `<div class="lexxy-content">` layout, sanitized, and auto-linked. Like Rails it rescues any
     * error and renders "", logging it (a body without a rich text record raises there too).
     */
    public function render(?string $storedBodyHtml): string
    {
        if (null === $storedBodyHtml) {
            return '';
        }
        $context = $this->context();
        try {
            $filtered = TextMessagePresentationFilters::apply(Content::load($storedBodyHtml, $context), $context);

            return AutoLink::autoLink($filtered->toRenderedHtmlWithLayout($context), SafeList::autoLink());
        } catch (\Throwable $e) {
            // `rescue Exception` in message_presentation
            $this->logger->error('Exception while generating message representation, failed with: {class} `{message}`', ['class' => $e::class, 'message' => $e->getMessage()]);

            return '';
        }
    }

    /**
     * `message.body.to_plain_text` (ActionText::RichText#to_plain_text): mentions become "@Name",
     * link previews nothing. Message#plain_text_body adds the attachment filename fallback.
     *
     * @throws RichTextError where Rails raises (markup past Gumbo's limits, say)
     */
    public function toPlainText(?string $storedBodyHtml): string
    {
        if (null === $storedBodyHtml) {
            return '';
        }
        $context = $this->context();

        return Content::load($storedBodyHtml, $context)->toPlainText($context);
    }

    /**
     * The value messages/edit.html.erb gives the `<lexxy-editor>`: RichTextHelper#editable_body
     * (reference/app/helpers/rich_text_helper.rb) then Lexxy's `render_custom_attachments_in`.
     * Unescaped; the template escapes it into the `value` attribute. "" for a blank body, where
     * Rails omits the attribute.
     *
     * @throws RichTextError where Rails raises
     */
    public function forEditor(?string $storedBodyHtml): string
    {
        if (null === $storedBodyHtml) {
            return '';
        }

        return Content::editableValue($storedBodyHtml, $this->context()) ?? '';
    }

    /**
     * `message.body.to_s` (Content#to_s, the messages/_message.json.jbuilder `html`): the body
     * rendered with attachments in the lexxy-content layout, without the content filters and
     * auto-linking.
     *
     * @throws RichTextError where Rails raises
     */
    public function toRenderedHtmlWithLayout(?string $storedBodyHtml): string
    {
        if (null === $storedBodyHtml) {
            return '';
        }
        $context = $this->context();

        return Content::load($storedBodyHtml, $context)->toRenderedHtmlWithLayout($context);
    }

    /**
     * `message.body.body` serialized (Content#as_json, the webhook payload's `body.html`): the
     * stored body loaded, i.e. canonicalized again.
     *
     * @throws RichTextError where Rails raises
     */
    public function toHtml(?string $storedBodyHtml): string
    {
        if (null === $storedBodyHtml) {
            return '';
        }

        return Content::load($storedBodyHtml, $this->context())->toHtml();
    }

    public function context(): RenderContext
    {
        return new RenderContext($this->locator, $this->current->request()?->getHost() ?? '');
    }
}
