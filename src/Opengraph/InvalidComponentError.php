<?php

declare(strict_types=1);

namespace App\Opengraph;

/** URI::InvalidComponentError: Ruby raises it where callers only rescue URI::InvalidURIError. */
final class InvalidComponentError extends \RuntimeException
{
}
