<?php

declare(strict_types=1);

namespace App\Controller\ActiveStorage\Representations;

use App\Http\Attribute\NotApplicationController;
use App\Storage\DiskService;
use App\Storage\Http\BlobStreaming;
use App\Storage\Http\RepresentationLookup;
use App\Storage\Http\Responses;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ActiveStorage::Representations::ProxyController: the processed representation streamed through
 * the app and cached forever. ActiveStorage::DisableSession: no session is loaded.
 */
#[NotApplicationController]
#[Route(defaults: ['_format' => null])]
final class ProxyController extends AbstractController
{
    public function __construct(
        private readonly RepresentationLookup $lookup,
        private readonly DiskService $disk,
    ) {
    }

    #[Route('/rails/active_storage/representations/proxy/{signed_blob_id}/{variation_key}/{filename}.{_format}', name: 'rails_blob_representation_proxy', methods: ['GET'], requirements: ['filename' => '.+?'], priority: 5)]
    public function show(Request $request): Response
    {
        $image = $this->lookup->processed($request);
        if (null === $image) {
            return Responses::head($request, 404, fromBeforeAction: true);
        }

        return BlobStreaming::sendForever($request, $this->disk, $image, $request->query->all()['disposition'] ?? null, withLength: false);
    }
}
