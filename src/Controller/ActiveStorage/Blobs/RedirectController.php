<?php

declare(strict_types=1);

namespace App\Controller\ActiveStorage\Blobs;

use App\Http\Attribute\NotApplicationController;
use App\Storage\Http\BlobLookup;
use App\Storage\Http\Responses;
use App\Storage\ServiceUrls;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ActiveStorage::Blobs::RedirectController: a signed permanent blob reference turned into an
 * expiring disk URL.
 */
#[NotApplicationController]
#[Route(defaults: ['_format' => null])]
final class RedirectController extends AbstractController
{
    public function __construct(
        private readonly BlobLookup $lookup,
        private readonly ServiceUrls $serviceUrls,
    ) {
    }

    #[Route('/rails/active_storage/blobs/redirect/{signed_id}/{filename}.{_format}', name: 'rails_service_blob', methods: ['GET'], requirements: ['filename' => '.+?'], priority: 9)]
    #[Route('/rails/active_storage/blobs/{signed_id}/{filename}.{_format}', name: 'rails_service_blob_legacy', methods: ['GET'], requirements: ['filename' => '.+?'], priority: 7)]
    public function show(Request $request): Response
    {
        $blob = $this->lookup->findSigned($request->attributes->get('signed_id'));
        if (null === $blob) {
            return Responses::head($request, 404, fromBeforeAction: true);
        }

        // expires_in ActiveStorage.service_urls_expire_in; redirect_to @blob.url(disposition: params[:disposition])
        $response = ServiceUrls::redirect($this->serviceUrls->blobUrl($request, $blob, self::disposition($request)));
        Responses::expiresIn($response, ServiceUrls::EXPIRES_IN);

        return $response;
    }

    private static function disposition(Request $request): ?string
    {
        $disposition = $request->query->all()['disposition'] ?? null;

        return \is_string($disposition) ? $disposition : null;
    }
}
