<?php

declare(strict_types=1);

namespace App\Controller\Accounts;

use App\Controller\ApplicationController;
use App\Domain\Accounts\Accounts;
use App\Http\Concerns\ImplicitRender;
use App\Http\Concerns\PermitsParams;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Accounts::CustomStylesController (reference/app/controllers/accounts/custom_styles_controller.rb).
 */
#[Route(defaults: ['_format' => null])]
final class CustomStylesController extends ApplicationController
{
    use ImplicitRender;
    use PermitsParams;

    public function __construct(private readonly Accounts $accounts)
    {
    }

    #[Route('/account/custom_styles/edit.{_format}', name: 'edit_account_custom_styles', methods: ['GET'], priority: 138)]
    public function edit(Request $request): Response
    {
        if (null !== $response = $this->ensureCanAdminister()) {
            return $response;
        }

        return $this->renderHtml($request, 'accounts/custom_styles/edit.html.twig', ['account' => $this->current()->account()]);
    }

    #[Route('/account/custom_styles.{_format}', name: 'account_custom_styles', methods: ['PATCH'], priority: 137)]
    #[Route('/account/custom_styles.{_format}', name: 'account_custom_styles.put', methods: ['PUT'], priority: 136)]
    public function update(Request $request): Response
    {
        if (null !== $response = $this->ensureCanAdminister()) {
            return $response;
        }
        $this->accounts->update($this->current()->account() ?? throw new \LogicException('No account'), $this->requirePermitted($request, 'account', 'custom_styles'));

        return $this->redirectTo($this->generateUrl('edit_account_custom_styles', [], UrlGeneratorInterface::ABSOLUTE_URL), notice: '✓');
    }
}
