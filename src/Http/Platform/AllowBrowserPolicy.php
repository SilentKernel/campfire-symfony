<?php

declare(strict_types=1);

namespace App\Http\Platform;

use App\Http\BrowserPolicy;
use App\Http\Mime;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * `allow_browser versions: AllowBrowser::VERSIONS, block: -> { render template:
 * "sessions/incompatible_browser" }` (reference/app/controllers/concerns/allow_browser.rb):
 * blocked browsers get the incompatible-browser page with status 200.
 */
#[AsAlias(BrowserPolicy::class)]
final readonly class AllowBrowserPolicy implements BrowserPolicy
{
    public function __construct(private Environment $twig)
    {
    }

    public function check(Request $request): ?Response
    {
        if (!BrowserBlocker::blocked($request->headers->get('User-Agent'))) {
            return null;
        }

        if (!$request->attributes->has(ApplicationPlatform::ATTRIBUTE)) {
            $request->attributes->set(ApplicationPlatform::ATTRIBUTE, new ApplicationPlatform($request->headers->get('User-Agent')));
        }

        $response = new Response($this->twig->render('sessions/incompatible_browser.html.twig'), Response::HTTP_OK, ['Content-Type' => 'text/html; charset=utf-8']);
        if (Mime::shouldApplyVaryHeader($request)) {
            $response->headers->set('Vary', 'Accept');
        }

        return $response;
    }
}
