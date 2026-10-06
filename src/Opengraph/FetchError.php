<?php

declare(strict_types=1);

namespace App\Opengraph;

/** A fetch that Rails would end by raising (too many redirects, a denied redirect, a network error). */
final class FetchError extends \RuntimeException
{
}
