<?php

declare(strict_types=1);

namespace App\Controller;

use App\Opengraph\Unfurler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * UnfurlLinksController (reference/app/controllers/unfurl_links_controller.rb): the composer
 * asks for a pasted URL's OpenGraph metadata.
 */
#[Route(defaults: ['_format' => null])]
final class UnfurlLinksController extends ApplicationController
{
    public function __construct(private readonly Unfurler $unfurler)
    {
    }

    #[Route('/unfurl_link.{_format}', name: 'unfurl_link', methods: ['POST'], priority: 30)]
    public function create(Request $request): Response
    {
        // params.require(:url): a blank or missing url is a 400. A hash or an array passes, but
        // URI.parse can't take it, so nothing unfurls.
        $url = $this->params($request)->require('url');
        if (!\is_string($url)) {
            return $this->head(Response::HTTP_NO_CONTENT);
        }

        $json = $this->unfurler->unfurl($url);
        if (null === $json) {
            return $this->head(Response::HTTP_NO_CONTENT);
        }

        return new Response($json, Response::HTTP_OK, ['Content-Type' => 'application/json; charset=utf-8']);
    }
}
