<?php

declare(strict_types=1);

namespace App\Controller\Accounts;

use App\Controller\ApplicationController;
use App\Domain\Accounts\Accounts;
use App\Http\Concerns\ImplicitRender;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Accounts::JoinCodesController (reference/app/controllers/accounts/join_codes_controller.rb).
 */
#[Route(defaults: ['_format' => null])]
final class JoinCodesController extends ApplicationController
{
    use ImplicitRender;

    public function __construct(private readonly Accounts $accounts)
    {
    }

    #[Route('/account/join_code.{_format}', name: 'account_join_code', methods: ['POST'], priority: 141)]
    public function create(): Response
    {
        if (null !== $response = $this->ensureCanAdminister()) {
            return $response;
        }
        $this->accounts->resetJoinCode($this->current()->account() ?? throw new \LogicException('No account'));

        return $this->redirectTo($this->generateUrl('edit_account', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }
}
