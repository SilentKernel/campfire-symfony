<?php

declare(strict_types=1);

namespace App\Opengraph;

use App\Rails\RailsJson;

/**
 * Opengraph::Metadata (reference/app/models/opengraph/metadata.rb): the attributes are kept as
 * Ruby keeps instance variables, in the order they were first assigned, because that is the
 * order `render json: opengraph` (Object#as_json, i.e. instance_values) emits them.
 */
final class Metadata
{
    public const array ATTRIBUTES = ['title', 'url', 'image', 'description'];

    private bool $validated = false;

    /** @param array<string, ?string> $attributes */
    public function __construct(private array $attributes)
    {
    }

    public function get(string $key): ?string
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * `valid?`: before_validation sanitizes the title and description
     * (`sanitize(strip_tags(value))`), then presence of title, url and description, and a valid
     * image location when there is an image.
     *
     * @param callable(string): bool $isValidLocation `Opengraph::Location.new(url).valid?`
     */
    public function isValid(callable $isValidLocation): bool
    {
        foreach (['title', 'description'] as $key) {
            $value = $this->attributes[$key] ?? null;
            $this->attributes[$key] = null === $value ? null : self::stripTags($value);
        }
        $this->validated = true;

        $valid = !Document::isBlank($this->get('title')) && !Document::isBlank($this->get('url')) && !Document::isBlank($this->get('description'));
        $image = $this->get('image');
        if (!Document::isBlank($image)) {
            $valid = $isValidLocation((string) $image) && $valid;
        }

        return $valid;
    }

    /** `opengraph.to_json` after `valid?`: the attributes, then the validation context and the errors. */
    public function toJson(): string
    {
        $values = new \stdClass();
        foreach ($this->attributes as $key => $value) {
            $values->{$key} = $value;
        }
        if ($this->validated) {
            $values->context_for_validation = ['context' => null];
            $values->errors = new \stdClass();
        }

        return RailsJson::encode($values);
    }

    /**
     * `strip_tags` (Rails::HTML5::FullSanitizer): the text of the HTML5 fragment, serialized as
     * HTML text (&, <, > and no-break spaces escaped). The SafeListSanitizer pass that follows
     * leaves such text unchanged.
     */
    public static function stripTags(string $html): string
    {
        if ('' === $html) {
            return '';
        }
        $document = \Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><head></head><body>'.mb_scrub($html, 'UTF-8'), \LIBXML_NOERROR, 'UTF-8');
        $text = (string) $document->body?->textContent;

        return str_replace(['&', '<', '>', "\u{00A0}"], ['&amp;', '&lt;', '&gt;', '&nbsp;'], $text);
    }
}
