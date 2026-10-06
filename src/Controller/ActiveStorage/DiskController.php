<?php

declare(strict_types=1);

namespace App\Controller\ActiveStorage;

use App\Http\Attribute\NotApplicationController;
use App\Http\Attribute\SkipForgeryProtection;
use App\Http\Mime;
use App\Security\Authentication;
use App\Storage\DiskService;
use App\Storage\FileNotFound;
use App\Storage\Http\FileServer;
use App\Storage\Http\Responses;
use App\Storage\IntegrityError;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ActiveStorage::DiskController: serves files by their expiring signed key, and takes direct
 * uploads by their signed token. Campfire adds `require_active_storage_authentication` to update
 * (reference/config/initializers/active_storage_authentication.rb) and `Cache-Control:
 * max-age=3600, public` after show (reference/config/initializers/active_storage.rb).
 */
#[NotApplicationController]
#[SkipForgeryProtection]
#[Route(defaults: ['_format' => null])]
final class DiskController extends AbstractController
{
    public function __construct(
        private readonly DiskService $disk,
        private readonly Authentication $authentication,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/rails/active_storage/disk/{encoded_key}/{filename}.{_format}', name: 'rails_disk_service', methods: ['GET'], requirements: ['filename' => '.+?'], priority: 3)]
    public function show(Request $request): Response
    {
        $encodedKey = $request->attributes->get('encoded_key');
        $key = \is_string($encodedKey) ? $this->disk->decodeKey($encodedKey, $this->clock->now()) : null;
        if (null === $key) {
            $response = Responses::head($request, 404);
        } else {
            try {
                $response = FileServer::serve($request, $this->disk->pathFor($key['key']), $key['content_type'], $key['disposition']);
            } catch (FileNotFound) {
                $response = Responses::head($request, 404);
            }
        }
        // after_action (reference/config/initializers/active_storage.rb)
        Responses::setCacheControl($response, 'max-age=3600, public');

        return $response;
    }

    #[Route('/rails/active_storage/disk/{encoded_token}.{_format}', name: 'update_rails_disk_service', methods: ['PUT'], priority: 2)]
    public function update(Request $request): Response
    {
        // require_active_storage_authentication
        if (null === $this->authentication->findSessionByCookie()) {
            return Responses::head($request, 401, fromBeforeAction: true);
        }

        $encodedToken = $request->attributes->get('encoded_token');
        $token = \is_string($encodedToken) ? $this->disk->decodeToken($encodedToken, $this->clock->now()) : null;
        if (null === $token) {
            return Responses::head($request, 404);
        }
        if (!self::acceptableContent($token, $request)) {
            return Responses::head($request, 422);
        }

        try {
            $this->disk->upload((string) $token['key'], $request->getContent(true), \is_string($token['checksum'] ?? null) ? $token['checksum'] : null);
        } catch (IntegrityError) {
            return Responses::head($request, 422);
        }

        return Responses::head($request, 204);
    }

    /**
     * `token[:content_type] == request.content_mime_type && token[:content_length] == request.content_length`.
     *
     * @param array<string, mixed> $token
     */
    private static function acceptableContent(array $token, Request $request): bool
    {
        $header = $request->headers->get('Content-Type');
        $requestType = null === $header ? null : strtolower(trim(explode(',', explode(';', $header, 2)[0], 2)[0]));
        $tokenType = $token['content_type'] ?? null;
        $sameType = match (true) {
            null === $tokenType || null === $requestType || '' === $requestType => null === $tokenType && (null === $requestType || '' === $requestType),
            $tokenType === $requestType => true,
            default => null !== ($symbol = Mime::lookup($requestType)) && Mime::lookup((string) $tokenType) === $symbol,
        };

        // request.content_length: CONTENT_LENGTH.to_i
        return $sameType && ($token['content_length'] ?? null) === (int) $request->headers->get('Content-Length', '0');
    }
}
