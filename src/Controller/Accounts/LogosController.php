<?php

declare(strict_types=1);

namespace App\Controller\Accounts;

use App\Controller\ApplicationController;
use App\Http\Attribute\AllowUnauthenticatedAccess;
use App\Storage\Attachments;
use App\Storage\DiskService;
use App\Storage\Http\AppAssets;
use App\Storage\Http\Responses;
use App\Storage\Representations;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accounts::LogosController (reference/app/controllers/accounts/logos_controller.rb): the account
 * logo's :large or :small PNG variant, or the stock app icon; public, so it can be the PWA icon.
 */
#[Route(defaults: ['_format' => null])]
final class LogosController extends ApplicationController
{
    /** `expires_in 5.minutes, public: true, stale_while_revalidate: 1.week` */
    private const int MAX_AGE = 5 * 60;
    private const int STALE_WHILE_REVALIDATE = 7 * 24 * 60 * 60;

    public function __construct(
        private readonly Representations $representations,
        private readonly Attachments $attachments,
        private readonly DiskService $disk,
        private readonly AppAssets $assets,
    ) {
    }

    #[AllowUnauthenticatedAccess]
    #[Route('/account/logo.{_format}', name: 'account_logo', methods: ['GET'], priority: 140)]
    public function show(Request $request): Response
    {
        $account = $this->current()->account();

        // stale?(etag: Current.account): there is no accounts/logos/show template to digest.
        $etag = null === $account ? null : Responses::weakEtag([Responses::cacheKeyWithVersion('accounts', $account->getId(), $account->getUpdatedAt())]);
        if (null !== $etag && Responses::isFresh($request, $etag)) {
            return new Response('', 304, ['ETag' => $etag]);
        }

        $small = 'small' === $this->params($request)->get('size');
        $variant = null === $account ? null : $this->representations->processedNamedVariant('Account', $account->getId(), 'logo', $small ? 'small' : 'large');
        $path = null !== $variant
            ? $this->disk->pathFor($variant->getKey())
            : $this->assets->image('logos/'.($small ? 'app-icon-192.png' : 'app-icon.png'));

        $response = Responses::sendFile($request, $path, 'image/png');
        if (null !== $etag) {
            $response->headers->set('ETag', $etag);
        }
        Responses::expiresIn($response, self::MAX_AGE, public: true, staleWhileRevalidate: self::STALE_WHILE_REVALIDATE);

        return $response;
    }

    #[Route('/account/logo.{_format}', name: 'account_logo.delete', methods: ['DELETE'], priority: 139)]
    public function destroy(): Response
    {
        // before_action :ensure_can_administer
        $this->requireAdministrator();
        $account = $this->current()->account() ?? throw new \LogicException('No account.');
        $this->attachments->detach('Account', $account->getId(), 'logo');

        return $this->redirectTo($this->generateUrl('edit_account'));
    }
}
