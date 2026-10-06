<?php

declare(strict_types=1);

namespace App\RichText;

use App\Http\Current;
use App\RichText\Attachables\AttachableLocator;

/**
 * What Action Text stores when a body is assigned (`ActionText::Content.dump(body)`, i.e.
 * `Content.new(body).to_html`): the markup parsed as an HTML5 fragment and serialized again,
 * Trix attachments converted to `<action-text-attachment>`, attachments emptied of their
 * content, galleries canonicalized. Messages created or updated by people and bots (whose plain
 * text bodies are parsed as HTML too) store this.
 */
final readonly class Canonicalizer
{
    public function __construct(
        private AttachableLocator $locator,
        private Current $current,
    ) {
    }

    /**
     * @throws RichTextError where Rails raises while saving (markup past Gumbo's limits, a Trix
     *                       attachment that can't be resolved)
     */
    public function canonicalize(string $submittedHtml): string
    {
        $context = new RenderContext($this->locator, $this->current->request()?->getHost() ?? '');

        return Content::load($submittedHtml, $context)->toHtml();
    }
}
