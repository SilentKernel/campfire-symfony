<?php

declare(strict_types=1);

namespace App\Twig;

use App\Twig\Html\RecordIdentifier;
use App\Twig\Html\Tag;
use App\Twig\View\ViewHelpers;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * ActionView's view primitives with Rails' names. Options are Twig hashes in Ruby keyword order
 * (attribute order is preserved): `{{ link_to('Edit', path('edit_account'), {class: 'btn', data: {turbo_frame: '_top'}}) }}`.
 * Helpers that take a block in Rails take the block's HTML as an argument (build it with
 * `{% set content %}…{% endset %}`): `button_to(content, url, options)`, `content_tag(name, content, options)`.
 */
final class TagExtension extends AbstractExtension
{
    public function __construct(private readonly ViewHelpers $helpers)
    {
    }

    public function getFunctions(): array
    {
        $safe = ['is_safe' => ['html']];

        return [
            new TwigFunction('content_tag', Tag::content(...), $safe),
            new TwigFunction('html_tag', $this->htmlTag(...), $safe),
            new TwigFunction('tag', $this->legacyTag(...), $safe),
            new TwigFunction('tag_attributes', static fn (array $attributes): string => ltrim(Tag::options($attributes)), $safe),
            new TwigFunction('token_list', static fn (array $args = []): string => Tag::tokenList(...$args), $safe + ['is_variadic' => true]),
            new TwigFunction('class_names', static fn (array $args = []): string => Tag::tokenList(...$args), $safe + ['is_variadic' => true]),
            new TwigFunction('safe_join', $this->safeJoin(...), $safe),
            new TwigFunction('dom_id', RecordIdentifier::domId(...)),
            new TwigFunction('dom_class', RecordIdentifier::domClass(...)),
            new TwigFunction('link_to', $this->helpers->linkTo(...), $safe),
            new TwigFunction('button_to', $this->helpers->buttonTo(...), $safe),
            new TwigFunction('form_with', $this->helpers->formWith(...), $safe),
            new TwigFunction('auto_submit_form_with', $this->autoSubmitFormWith(...), $safe),
            new TwigFunction('token_tag', $this->tokenTag(...), $safe),
            new TwigFunction('method_tag', $this->helpers->methodTag(...), $safe),
            new TwigFunction('csrf_meta_tags', $this->helpers->csrfMetaTags(...), $safe),
            new TwigFunction('csp_meta_tag', static fn (): string => ''),
            new TwigFunction('drop_target_actions', static fn (): string => 'dragenter->drop-target#dragenter dragover->drop-target#dragover drop->drop-target#drop'),
        ];
    }

    /**
     * `tag.<name>(content, **options)`: void elements render `<name …>`, others a content tag
     * (content escaped unless it is Markup).
     *
     * @param array<string, mixed> $options
     */
    public function htmlTag(string $name, array $options = [], mixed $content = null): string
    {
        return \in_array($name, ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'keygen', 'link', 'meta', 'source', 'track', 'wbr'], true)
            ? Tag::void($name, $options)
            : Tag::content($name, $content, $options);
    }

    /**
     * Legacy `tag(name, options, open)`: `<name … />`.
     *
     * @param array<string, mixed> $options
     */
    public function legacyTag(string $name, array $options = [], bool $open = false): string
    {
        return Tag::legacy($name, $options, $open);
    }

    /**
     * `auto_submit_form_with(**attributes)` (reference/app/helpers/forms_helper.rb): form_with with
     * "auto-submit" prepended to data-controller.
     *
     * @param array<string, mixed> $options
     */
    public function autoSubmitFormWith(array $options = []): string
    {
        $data = $options['data'] ?? [];
        unset($options['data']);
        $data['controller'] = trim('auto-submit '.($data['controller'] ?? ''));
        $options['data'] = $data;

        return $this->helpers->formWith($options);
    }

    /** `token_tag(nil, form_options: {action:, method:})`: the per-form authenticity_token field. */
    public function tokenTag(string $action, string $method = 'post'): string
    {
        return $this->helpers->tokenTag(null, $action, $method);
    }

    /** @param iterable<mixed> $values */
    public function safeJoin(iterable $values, string $separator = "\n"): string
    {
        $out = [];
        foreach ($values as $value) {
            $out[] = ViewHelpers::h($value instanceof Markup ? $value : (null === $value ? '' : $value));
        }

        return implode(ViewHelpers::h($separator), $out);
    }
}
