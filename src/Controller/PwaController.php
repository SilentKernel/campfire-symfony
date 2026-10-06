<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Attribute\AllowUnauthenticatedAccess;
use App\Http\Attribute\SkipForgeryProtection;
use App\Http\Mime;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PwaController (reference/app/controllers/pwa_controller.rb).
 */
#[AllowUnauthenticatedAccess]
#[SkipForgeryProtection]
#[Route(defaults: ['_format' => null])]
final class PwaController extends ApplicationController
{
    #[Route('/webmanifest.{_format}', name: 'webmanifest', methods: ['GET'], priority: 29)]
    public function manifest(Request $request): Response
    {
        return $this->respondTo($request, ['json' => fn (): Response => $this->render('pwa/manifest.json.twig', [], new Response('', Response::HTTP_OK, ['Content-Type' => 'application/json; charset=utf-8']))]);
    }

    #[Route('/service-worker.{_format}', name: 'service_worker', methods: ['GET'], priority: 28)]
    public function serviceWorker(Request $request): Response
    {
        return $this->respondTo($request, ['js' => fn (): Response => new Response(
            (string) file_get_contents(\dirname(__DIR__, 2).'/templates/pwa/service_worker.js'),
            Response::HTTP_OK,
            ['Content-Type' => Mime::typeOf('js').'; charset=utf-8'],
        )]);
    }
}
