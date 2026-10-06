<?php

declare(strict_types=1);

namespace App\Controller\Accounts\Bots;

use App\Controller\ApplicationController;
use App\Domain\Rooms\RubyInteger;
use App\Domain\Users\Bots;
use App\Http\Concerns\ImplicitRender;
use App\Http\Exception\RecordNotFound;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Accounts::Bots::KeysController (reference/app/controllers/accounts/bots/keys_controller.rb).
 */
#[Route(defaults: ['_format' => null])]
final class KeysController extends ApplicationController
{
    use ImplicitRender;

    public function __construct(
        private readonly Bots $bots,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('/account/bots/{bot_id}/key.{_format}', name: 'account_bot_key', methods: ['PATCH'], priority: 151)]
    #[Route('/account/bots/{bot_id}/key.{_format}', name: 'account_bot_key.put', methods: ['PUT'], priority: 150)]
    public function update(Request $request): Response
    {
        if (null !== $response = $this->ensureCanAdminister()) {
            return $response;
        }

        // `User.active_bots.find(params[:bot_id]).reset_bot_key`
        $id = RubyInteger::cast($request->attributes->get('bot_id'));
        $bot = null === $id ? null : $this->users->findActive($id);
        if (null === $bot || !$bot->isBot()) {
            throw RecordNotFound::for('User', $request->attributes->get('bot_id'));
        }
        $this->bots->resetBotKey($bot);

        return $this->redirectTo($this->generateUrl('account_bots', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }
}
