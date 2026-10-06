<?php

declare(strict_types=1);

namespace App\Controller\Sessions;

use App\Controller\ApplicationController;
use App\Domain\Users\Transfers;
use App\Http\Attribute\AllowUnauthenticatedAccess;
use App\Http\Concerns\ImplicitRender;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sessions::TransfersController (reference/app/controllers/sessions/transfers_controller.rb):
 * sign in with a transfer link (a signed user id, purpose :transfer, valid 4 hours).
 */
#[AllowUnauthenticatedAccess]
#[Route(defaults: ['_format' => null])]
final class TransfersController extends ApplicationController
{
    use ImplicitRender;

    public function __construct(private readonly Transfers $transfers)
    {
    }

    #[Route('/session/transfers/{id}.{_format}', name: 'session_transfer', methods: ['GET'], priority: 169)]
    public function show(Request $request): Response
    {
        return $this->renderHtml($request, 'sessions/transfers/show.html.twig');
    }

    #[Route('/session/transfers/{id}.{_format}', name: 'session_transfer.patch', methods: ['PATCH'], priority: 168)]
    #[Route('/session/transfers/{id}.{_format}', name: 'session_transfer.put', methods: ['PUT'], priority: 167)]
    public function update(Request $request): Response
    {
        $user = $this->transfers->findActiveUser((string) $request->attributes->get('id'));
        if (null === $user) {
            return $this->head(Response::HTTP_BAD_REQUEST);
        }

        $this->startNewSessionFor($user);

        return $this->redirectTo($this->postAuthenticatingUrl());
    }
}
