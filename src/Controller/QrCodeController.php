<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Users\QrCodeSvg;
use App\Http\Attribute\AllowUnauthenticatedAccess;
use App\Http\Mime;
use App\Rails\Base64;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * QrCodeController (reference/app/controllers/qr_code_controller.rb).
 */
#[AllowUnauthenticatedAccess]
#[Route(defaults: ['_format' => null])]
final class QrCodeController extends ApplicationController
{
    #[Route('/qr_code/{id}.{_format}', name: 'qr_code', methods: ['GET'], priority: 126)]
    public function show(Request $request): Response
    {
        $url = Base64::urlsafeDecode((string) $request->attributes->get('id'));
        if (null === $url) {
            // Base64.urlsafe_decode64 raises ArgumentError.
            throw new \InvalidArgumentException('invalid base64');
        }

        // `expires_in 1.year, public: true` (1.year is 365.2425 days); `render plain:`.
        $response = new Response(QrCodeSvg::render($url), Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'max-age=31556952, public',
        ]);
        if (Mime::shouldApplyVaryHeader($request)) {
            $response->headers->set('Vary', 'Accept');
        }

        return $response;
    }
}
