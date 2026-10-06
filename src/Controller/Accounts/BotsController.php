<?php

declare(strict_types=1);

namespace App\Controller\Accounts;

use App\Controller\ApplicationController;
use App\Domain\Rooms\RubyInteger;
use App\Domain\Users\Bots;
use App\Domain\Users\Users;
use App\Entity\User;
use App\Http\Attribute\ActionNotFound;
use App\Http\Concerns\ImplicitRender;
use App\Http\Concerns\PermitsParams;
use App\Http\Exception\RecordNotFound;
use App\Repository\UserRepository;
use App\Repository\WebhookRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Accounts::BotsController (reference/app/controllers/accounts/bots_controller.rb).
 */
#[Route(defaults: ['_format' => null])]
final class BotsController extends ApplicationController
{
    use ImplicitRender;
    use PermitsParams;

    public function __construct(
        private readonly Bots $bots,
        private readonly Users $users,
        private readonly UserRepository $userRepository,
        private readonly WebhookRepository $webhooks,
    ) {
    }

    #[Route('/account/bots.{_format}', name: 'account_bots', methods: ['GET'], priority: 149)]
    public function index(Request $request): Response
    {
        if (null !== $response = $this->ensureCanAdminister()) {
            return $response;
        }

        return $this->renderHtml($request, 'accounts/bots/index.html.twig', ['bots' => $this->userRepository->findActiveBotsOrdered()]);
    }

    #[Route('/account/bots.{_format}', name: 'account_bots.post', methods: ['POST'], priority: 148)]
    public function create(Request $request): Response
    {
        if (null !== $response = $this->ensureCanAdminister()) {
            return $response;
        }
        $this->bots->create($this->botParams($request));

        return $this->redirectTo($this->generateUrl('account_bots', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    #[Route('/account/bots/new.{_format}', name: 'new_account_bot', methods: ['GET'], priority: 147)]
    public function new(Request $request): Response
    {
        if (null !== $response = $this->ensureCanAdminister()) {
            return $response;
        }

        return $this->renderHtml($request, 'accounts/bots/new.html.twig', ['bot' => null, 'webhook_url' => null]);
    }

    #[Route('/account/bots/{id}/edit.{_format}', name: 'edit_account_bot', methods: ['GET'], priority: 146)]
    public function edit(Request $request): Response
    {
        if (null !== $response = $this->ensureCanAdminister()) {
            return $response;
        }
        $bot = $this->findBot($request);

        return $this->renderHtml($request, 'accounts/bots/edit.html.twig', ['bot' => $bot, 'webhook_url' => $this->webhooks->findOneForUser($bot)?->getUrl()]);
    }

    #[ActionNotFound]
    #[Route('/account/bots/{id}.{_format}', name: 'account_bot', methods: ['GET'], priority: 145)]
    public function show(): never
    {
        throw new NotFoundHttpException("The action 'show' could not be found for Accounts::BotsController");
    }

    #[Route('/account/bots/{id}.{_format}', name: 'account_bot.patch', methods: ['PATCH'], priority: 144)]
    #[Route('/account/bots/{id}.{_format}', name: 'account_bot.put', methods: ['PUT'], priority: 143)]
    public function update(Request $request): Response
    {
        if (null !== $response = $this->ensureCanAdminister()) {
            return $response;
        }
        $bot = $this->findBot($request);
        $this->bots->update($bot, $this->botParams($request));

        return $this->redirectTo($this->generateUrl('account_bots', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    #[Route('/account/bots/{id}.{_format}', name: 'account_bot.delete', methods: ['DELETE'], priority: 142)]
    public function destroy(Request $request): Response
    {
        if (null !== $response = $this->ensureCanAdminister()) {
            return $response;
        }
        $this->users->deactivate($this->findBot($request));

        return $this->redirectTo($this->generateUrl('account_bots', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    /** `set_bot`: `User.active_bots.find(params[:id])` */
    private function findBot(Request $request): User
    {
        $id = RubyInteger::cast($request->attributes->get('id'));
        $bot = null === $id ? null : $this->userRepository->findActive($id);
        if (null === $bot || !$bot->isBot()) {
            throw RecordNotFound::for('User', $request->attributes->get('id'));
        }

        return $bot;
    }

    /**
     * `params.require(:user).permit(:name, :avatar, :webhook_url)`.
     *
     * @return array{name?: mixed, avatar?: mixed, webhook_url?: mixed}
     */
    private function botParams(Request $request): array
    {
        return $this->requirePermitted($request, 'user', 'name', 'avatar', 'webhook_url');
    }
}
