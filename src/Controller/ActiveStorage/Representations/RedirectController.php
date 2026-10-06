<?php

declare(strict_types=1);

namespace App\Controller\ActiveStorage\Representations;

use App\Http\Attribute\NotApplicationController;
use App\Storage\Http\RepresentationLookup;
use App\Storage\Http\Responses;
use App\Storage\ServiceUrls;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ActiveStorage::Representations::RedirectController: processes the variant or preview on first
 * request, then redirects to its expiring disk URL.
 */
#[NotApplicationController]
#[Route(defaults: ['_format' => null])]
final class RedirectController extends AbstractController
{
    public function __construct(
        private readonly RepresentationLookup $lookup,
        private readonly ServiceUrls $serviceUrls,
    ) {
    }

    #[Route('/rails/active_storage/representations/redirect/{signed_blob_id}/{variation_key}/{filename}.{_format}', name: 'rails_blob_representation', methods: ['GET'], requirements: ['filename' => '.+?'], priority: 6)]
    #[Route('/rails/active_storage/representations/{signed_blob_id}/{variation_key}/{filename}.{_format}', name: 'rails_blob_representation_legacy', methods: ['GET'], requirements: ['filename' => '.+?'], priority: 4)]
    public function show(Request $request): Response
    {
        $image = $this->lookup->processed($request);
        if (null === $image) {
            return Responses::head($request, 404, fromBeforeAction: true);
        }

        $disposition = $request->query->all()['disposition'] ?? null;
        $response = ServiceUrls::redirect($this->serviceUrls->blobUrl($request, $image, \is_string($disposition) ? $disposition : null));
        Responses::expiresIn($response, ServiceUrls::EXPIRES_IN);

        return $response;
    }
}
