<?php

declare(strict_types=1);

namespace App\Twig\Escaper;

use Twig\Extension\AbstractExtension;
use Twig\Extension\EscaperExtension;
use Twig\TwigFilter;

/**
 * Replaces Twig's escape/e filters, which auto-escaping compiles to, with ErbEscaper so that the
 * markup matches ERB's (' → &#39;). Twig's EscaperRuntime is final and its html strategy cannot be
 * overridden with setEscaper(); a later extension's filter of the same name wins instead.
 */
final class ErbEscaperExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        $options = ['needs_environment' => true, 'is_safe_callback' => [EscaperExtension::class, 'escapeFilterIsSafe']];

        return [
            new TwigFilter('escape', [ErbEscaper::class, 'escape'], $options),
            new TwigFilter('e', [ErbEscaper::class, 'escape'], $options),
        ];
    }
}
