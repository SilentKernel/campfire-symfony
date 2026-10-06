<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** A BrowserPolicy letting every browser through (the application uses Platform\AllowBrowserPolicy). */
final class AllowAllBrowserPolicy implements BrowserPolicy
{
    public function check(Request $request): ?Response
    {
        return null;
    }
}
