<?php

declare(strict_types=1);

namespace App\Twig\Html;

/** The ActiveSupport::Inflector methods views rely on (no custom acronyms are defined). */
final class Inflector
{
    /** "Rooms::Open" → "rooms/open", "ActionText::RichText" → "action_text/rich_text". */
    public static function underscore(string $camelCased): string
    {
        if (!preg_match('/[A-Z-]|::/', $camelCased)) {
            return $camelCased;
        }

        $word = str_replace('::', '/', $camelCased);
        $word = preg_replace('/([A-Z\d]+)([A-Z][a-z])/', '$1_$2', $word);
        $word = preg_replace('/([a-z\d])([A-Z])/', '$1_$2', (string) $word);

        return strtolower(str_replace('-', '_', (string) $word));
    }

    public static function dasherize(string $underscored): string
    {
        return str_replace('_', '-', $underscored);
    }
}
