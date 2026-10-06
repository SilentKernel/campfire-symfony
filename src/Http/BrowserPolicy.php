<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `allow_browser` step of ApplicationController's chain
 * (reference/app/controllers/concerns/allow_browser.rb): null lets the request through, a
 * response (the incompatible-browser page) halts it.
 */
interface BrowserPolicy
{
    public function check(Request $request): ?Response;
}
