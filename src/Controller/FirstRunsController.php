<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Users\FirstRun;
use App\Domain\Users\RecordNotUnique;
use App\Http\Attribute\ActionNotFound;
use App\Http\Attribute\AllowUnauthenticatedAccess;
use App\Http\Concerns\ImplicitRender;
use App\Http\Concerns\PermitsParams;
use App\Repository\AccountRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * FirstRunsController (reference/app/controllers/first_runs_controller.rb).
 */
#[AllowUnauthenticatedAccess]
#[Route(defaults: ['_format' => null])]
final class FirstRunsController extends ApplicationController
{
    use ImplicitRender;
    use PermitsParams;

    public function __construct(
        private readonly FirstRun $firstRun,
        private readonly AccountRepository $accounts,
    ) {
    }

    #[ActionNotFound]
    #[Route('/first_run/new.{_format}', name: 'new_first_run', methods: ['GET'], priority: 176)]
    public function new(): never
    {
        throw new NotFoundHttpException("The action 'new' could not be found for FirstRunsController");
    }

    #[ActionNotFound]
    #[Route('/first_run/edit.{_format}', name: 'edit_first_run', methods: ['GET'], priority: 175)]
    public function edit(): never
    {
        throw new NotFoundHttpException("The action 'edit' could not be found for FirstRunsController");
    }

    #[Route('/first_run.{_format}', name: 'first_run', methods: ['GET'], priority: 174)]
    public function show(Request $request): Response
    {
        if (null !== $response = $this->preventRepeats()) {
            return $response;
        }

        return $this->renderHtml($request, 'first_runs/show.html.twig');
    }

    #[ActionNotFound]
    #[Route('/first_run.{_format}', name: 'first_run.patch', methods: ['PATCH'], priority: 173)]
    #[Route('/first_run.{_format}', name: 'first_run.put', methods: ['PUT'], priority: 172)]
    public function update(): never
    {
        throw new NotFoundHttpException("The action 'update' could not be found for FirstRunsController");
    }

    #[ActionNotFound]
    #[Route('/first_run.{_format}', name: 'first_run.delete', methods: ['DELETE'], priority: 171)]
    public function destroy(): never
    {
        throw new NotFoundHttpException("The action 'destroy' could not be found for FirstRunsController");
    }

    #[Route('/first_run.{_format}', name: 'first_run.post', methods: ['POST'], priority: 170)]
    public function create(Request $request): Response
    {
        if (null !== $response = $this->preventRepeats()) {
            return $response;
        }

        try {
            $user = $this->firstRun->create($this->requirePermitted($request, 'user', 'name', 'avatar', 'email_address', 'password'));
        } catch (RecordNotUnique) {
            return $this->redirectTo($this->generateUrl('root', [], UrlGeneratorInterface::ABSOLUTE_URL));
        }
        $this->startNewSessionFor($user);

        return $this->redirectTo($this->generateUrl('root', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    /** `prevent_repeats`: `redirect_to root_url if Account.any?` */
    private function preventRepeats(): ?Response
    {
        return 0 === $this->accounts->count([]) ? null : $this->redirectTo($this->generateUrl('root', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }
}
