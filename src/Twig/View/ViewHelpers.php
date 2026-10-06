<?php

declare(strict_types=1);

namespace App\Twig\View;

use App\Twig\Asset\Assets;
use App\Twig\Escaper\ErbEscaper;
use App\Twig\Html\Tag;
use Twig\Markup;

/**
 * The ActionView helpers Campfire's views use that need request state or assets: image_tag,
 * link_to, button_to, form_with, token_tag, csrf_meta_tags. Return values are HTML strings;
 * the Twig extensions mark them safe. Ported from actionview 8.2 (url_helper.rb,
 * form_tag_helper.rb, form_helper.rb, asset_tag_helper.rb, csrf_helper.rb).
 */
final class ViewHelpers
{
    public function __construct(
        private readonly Assets $assets,
        private readonly ViewContext $context,
    ) {
    }

    /**
     * `image_tag(source, **options)`: user options first, then src, then width/height from :size
     * ("24" or "24x32").
     *
     * @param array<string, mixed> $options
     */
    public function imageTag(string $source, array $options = []): string
    {
        $size = $options['size'] ?? null;
        unset($options['size'], $options['skip_pipeline']);
        $options['src'] = $this->assets->path($source);
        if (null !== $size && null !== $dimensions = self::extractDimensions((string) $size)) {
            [$options['width'], $options['height']] = $dimensions;
        }

        return Tag::legacy('img', $options);
    }

    /**
     * `link_to(name, url, html_options)`; with `method:` adds data-method (and rel="nofollow" for
     * non-GET), as convert_options_to_data_attributes does. The name is escaped unless Markup.
     *
     * @param array<string, mixed> $options
     */
    public function linkTo(mixed $name, string $url, array $options = []): string
    {
        if (\array_key_exists('method', $options)) {
            $method = $options['method'];
            unset($options['method']);
            if (null !== $method && false !== $method) {
                if ('get' !== strtolower((string) $method) && !str_contains((string) ($options['rel'] ?? ''), 'nofollow')) {
                    $options['rel'] = '' === trim((string) ($options['rel'] ?? '')) ? 'nofollow' : $options['rel'].' nofollow';
                }
                $options['data-method'] = $method;
            }
        }
        $options['href'] ??= $url;

        return Tag::content('a', $name ?? $url, $options);
    }

    /**
     * `button_to(name, url, html_options)` (button_to_generates_button_tag): a form.button_to with
     * the `_method` field for patch/put/delete, the button, then the per-form authenticity token.
     * `$content` is the button's content (the block, or the name).
     *
     * @param array<string, mixed> $options html options, plus method, form, form_class, params, authenticity_token
     */
    public function buttonTo(mixed $content, ?string $url, array $options = []): string
    {
        $params = $options['params'] ?? null;
        $authenticityToken = $options['authenticity_token'] ?? null;
        $method = strtolower(Tag::rubyToS($options['method'] ?? ''));
        $formOptions = $options['form'] ?? [];
        $formClass = $options['form_class'] ?? null;
        unset($options['remote'], $options['params'], $options['authenticity_token'], $options['method'], $options['form'], $options['form_class']);

        $methodTag = \in_array($method, ['patch', 'put', 'delete'], true) ? $this->methodTag($method) : '';
        $formMethod = 'get' === $method ? 'get' : 'post';
        $formOptions['class'] ??= $formClass ?? 'button_to';
        $formOptions['method'] = $formMethod;
        $formOptions['action'] = $url;

        $tokenTag = 'post' === $formMethod ? $this->tokenTag($authenticityToken, (string) $url, '' === $method ? 'post' : $method) : '';

        $options['type'] = 'submit';
        $inner = $methodTag.Tag::content('button', $content ?? $url, $options).$tokenTag;
        foreach (self::toFormParams($params ?? []) as [$name, $value]) {
            $inner .= Tag::legacy('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }

        return Tag::content('form', new Markup($inner, 'UTF-8'), $formOptions);
    }

    /**
     * The opening of `form_with(url:, method:, …) do … end`: `<form …>` followed by the hidden
     * `_method` and per-form authenticity token fields. Close it with `</form>`.
     *
     * @param array<string, mixed> $options url, method, id, class, multipart, data, authenticity_token, html, enforce_utf8
     */
    public function formWith(array $options = []): string
    {
        $html = [];
        foreach (['id', 'class', 'multipart', 'method', 'data', 'authenticity_token'] as $key) {
            if (\array_key_exists($key, $options)) {
                $html[$key] = $options[$key];
            }
        }
        $html = array_merge($html, $options['html'] ?? []);

        if (!empty($html['multipart'])) {
            $html['enctype'] = 'multipart/form-data';
        }
        unset($html['multipart']);
        $url = $options['url'] ?? null;
        if (false === $url || (\array_key_exists('action', $html) && false === $html['action'])) {
            unset($html['action']);
        } else {
            $html['action'] = $url ?? $this->context->request()?->getRequestUri() ?? '';
        }
        $html['accept-charset'] = 'UTF-8';
        if (true === ($html['authenticity_token'] ?? null)) {
            $html['authenticity_token'] = null;
        }

        return $this->formTagHtml($html, $options['enforce_utf8'] ?? false);
    }

    /**
     * `token_tag`: the hidden authenticity_token input with the per-form token for this action and
     * method ("" when $token is false; a given string token is used as-is).
     */
    public function tokenTag(string|bool|null $token, string $action, string $method = 'post'): string
    {
        if (false === $token) {
            return '';
        }
        if (null === $token || true === $token) {
            $token = $this->context->formCsrfToken($action, $method);
        }

        return Tag::legacy('input', ['type' => 'hidden', 'name' => $this->context->csrfParam(), 'value' => $token]);
    }

    /** `method_tag`: `<input type="hidden" name="_method" value="delete" />`. */
    public function methodTag(string $method): string
    {
        return Tag::legacy('input', ['type' => 'hidden', 'name' => '_method', 'value' => $method]);
    }

    /** `csrf_meta_tags`. */
    public function csrfMetaTags(): string
    {
        return Tag::legacy('meta', ['name' => 'csrf-param', 'content' => $this->context->csrfParam()])."\n"
            .Tag::legacy('meta', ['name' => 'csrf-token', 'content' => $this->context->csrfToken()]);
    }

    /** @return array{string, string}|null */
    public static function extractDimensions(string $size): ?array
    {
        if (preg_match('/\A\d+(?:\.\d+)?x\d+(?:\.\d+)?\z/', $size)) {
            $parts = explode('x', $size);

            return [$parts[0], $parts[1]];
        }

        return preg_match('/\A\d+(?:\.\d+)?\z/', $size) ? [$size, $size] : null;
    }

    /** @param array<string, mixed> $html */
    private function formTagHtml(array $html, bool $enforceUtf8): string
    {
        $authenticityToken = $html['authenticity_token'] ?? null;
        $method = strtolower(Tag::rubyToS($html['method'] ?? ''));
        unset($html['authenticity_token'], $html['method']);
        $action = (string) ($html['action'] ?? '');

        if ('get' === $method) {
            $html['method'] = 'get';
            $extra = '';
        } elseif ('post' === $method || '' === $method) {
            $html['method'] = 'post';
            $extra = $this->tokenTag($authenticityToken, $action, 'post');
        } else {
            $html['method'] = 'post';
            $extra = $this->methodTag($method).$this->tokenTag($authenticityToken, $action, $method);
        }
        if ($enforceUtf8) {
            $extra = Tag::legacy('input', ['name' => 'utf8', 'type' => 'hidden', 'value' => '✓', 'autocomplete' => 'off']).$extra;
        }

        return Tag::legacy('form', $html, true).$extra;
    }

    /**
     * `to_form_params`: nested hashes/arrays flattened to bracketed names.
     *
     * @return list<array{string, string}>
     */
    private static function toFormParams(mixed $attribute, ?string $namespace = null): array
    {
        $params = [];
        if (\is_array($attribute) && !array_is_list($attribute)) {
            foreach ($attribute as $key => $value) {
                array_push($params, ...self::toFormParams($value, null !== $namespace ? $namespace.'['.$key.']' : (string) $key));
            }
        } elseif (\is_array($attribute)) {
            foreach ($attribute as $value) {
                array_push($params, ...self::toFormParams($value, $namespace.'[]'));
            }
        } else {
            $params[] = [(string) $namespace, $attribute instanceof Markup ? (string) $attribute : Tag::rubyToS($attribute)];
        }

        return $params;
    }

    /** ERB-escaped text, for callers composing HTML by hand. */
    public static function h(mixed $value): string
    {
        return ErbEscaper::escapeValue($value);
    }
}
