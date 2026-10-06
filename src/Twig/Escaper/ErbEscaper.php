<?php

declare(strict_types=1);

namespace App\Twig\Escaper;

use Twig\Environment;
use Twig\Markup;
use Twig\Runtime\EscaperRuntime;

/**
 * HTML escaping exactly as ERB::Util.html_escape: & < > " ' become &amp; &lt; &gt; &quot; &#39;.
 * Twig's own html strategy writes ' as &#039;, so the "html" and "html_attr" strategies are routed
 * here (see ErbEscaperExtension); every other strategy is Twig's.
 */
final class ErbEscaper
{
    /** ERB::Util.html_escape on a plain (not html_safe) string. */
    public static function html(string $value): string
    {
        $escaped = htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return str_contains($value, "'") ? str_replace('&#039;', '&#39;', $escaped) : $escaped;
    }

    /** ERB::Util.html_escape: html_safe values (Twig Markup) pass through, nil becomes "". */
    public static function escapeValue(mixed $value): string
    {
        return match (true) {
            $value instanceof Markup => (string) $value,
            null === $value => '',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_string($value) => self::html($value),
            default => self::html((string) $value),
        };
    }

    /**
     * The escape filter (also used by auto-escaping): same signature as EscaperRuntime::escape.
     */
    public static function escape(Environment $env, mixed $value, ?string $strategy = 'html', ?string $charset = null, bool $autoescape = false): mixed
    {
        $strategy ??= 'html';
        if (('html' === $strategy || 'html_attr' === $strategy) && \is_string($value) && (null === $charset || 'UTF-8' === strtoupper($charset))) {
            return '' === $value ? '' : self::html($value);
        }

        $runtime = $env->getRuntime(EscaperRuntime::class);
        if ('html_attr' === $strategy) {
            $strategy = 'html';
        }
        $escaped = $runtime->escape($value, $strategy, $charset, $autoescape);

        // A Stringable that is not declared safe was escaped by htmlspecialchars: fix its quotes.
        if ('html' === $strategy && \is_string($escaped) && $value instanceof \Stringable && !($autoescape && $value instanceof Markup) && $escaped !== (string) $value) {
            return str_replace('&#039;', '&#39;', $escaped);
        }

        return $escaped;
    }
}
