<?php

declare(strict_types=1);

namespace App\RichText\Html;

use App\RichText\RichTextError;

/**
 * Nokogiri's ArgumentError for markup past Gumbo's limits (Nokogiri::Gumbo::DEFAULT_MAX_TREE_DEPTH
 * and DEFAULT_MAX_ATTRIBUTES, both 400).
 */
final class ParseError extends RichTextError
{
    public static function treeDepthExceeded(): self
    {
        return new self('Document tree depth limit exceeded');
    }

    public static function tooManyAttributes(): self
    {
        return new self('Attributes per element limit exceeded');
    }
}
