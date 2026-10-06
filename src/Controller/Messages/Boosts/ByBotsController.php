<?php

declare(strict_types=1);

namespace App\Controller\Messages\Boosts;

use App\Cable\Server\RubyInteger;
use App\Controller\ApplicationController;
use App\Controller\Messages\ByBotsController as MessagesByBotsController;
use App\Database\Transactions;
use App\Domain\Messages\MessageBroadcasts;
use App\Entity\Boost;
use App\Entity\Message;
use App\Entity\User;
use App\Http\Attribute\AllowBotAccess;
use App\Rails\RailsJson;
use App\Storage\RecordToucher;
use App\Twig\AvatarsExtension;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Messages::Boosts::ByBotsController (reference/app/controllers/messages/boosts/by_bots_controller.rb):
 * a bot boosts a message with the raw request body. In Rails it subclasses
 * Messages::BoostsController, whose destroy it inherits.
 */
#[Route(defaults: ['_format' => 'json'])]
final class ByBotsController extends ApplicationController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly Transactions $transactions,
        private readonly RecordToucher $toucher,
        private readonly MessageBroadcasts $broadcasts,
        private readonly AvatarsExtension $avatars,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    #[AllowBotAccess]
    #[Route('/rooms/{room_id}/{bot_key}/messages/{message_id}/boosts.{_format}', name: 'room_bot_message_boosts', methods: ['POST'], priority: 94)]
    public function create(Request $request): Response
    {
        $message = $this->setMessage($request);
        if (null === $message) {
            return $this->halted(404);
        }
        $content = MessagesByBotsController::rawRequestBody($request);
        // ensure_content_present
        if (MessagesByBotsController::isBlank($content)) {
            return $this->halted(422);
        }

        // `@message.boosts.create!(content:)`, touching the message (and so the room)
        $boost = $this->transactions->transaction(function () use ($message, $content): Boost {
            $boost = new Boost($message, $this->bot(), $content);
            $this->em->persist($boost);
            $this->em->flush();
            $this->toucher->touch(Message::RECORD_TYPE, $message->getId());

            return $boost;
        });
        $this->broadcasts->broadcastBoostCreate($boost);

        return new Response(RailsJson::encode($this->boostJson($boost)), 201, ['Content-Type' => MessagesByBotsController::JSON]);
    }

    /** Rails: inherited from Messages::BoostsController#destroy (no template: 204). */
    #[AllowBotAccess]
    #[Route('/rooms/{room_id}/{bot_key}/messages/{message_id}/boosts/{id}.{_format}', name: 'room_bot_message_boost', methods: ['DELETE'], priority: 93)]
    public function destroy(Request $request): Response
    {
        $message = $this->setMessage($request);
        if (null === $message) {
            return $this->halted(404);
        }
        // set_boost: `@message.boosts.find_by!(id:, booster: Current.user)`, RecordNotFound as 404
        $boostId = RubyInteger::cast($this->params($request)->get('id'));
        $found = null === $boostId ? false : $this->connection->fetchOne(
            'SELECT id FROM boosts WHERE message_id = ? AND id = ? AND booster_id = ? LIMIT 1',
            [$message->getId(), $boostId, $this->bot()->getId()],
        );
        $boost = false === $found ? null : $this->em->find(Boost::class, (int) $found);
        if (null === $boost) {
            return $this->halted(404);
        }

        // `@boost.destroy!`, touching the message, then broadcast_remove
        $this->transactions->transaction(function () use ($boost, $message): void {
            $this->connection->delete('boosts', ['id' => $boost->getId()]);
            $this->toucher->touch(Message::RECORD_TYPE, $message->getId());
        });
        $this->broadcasts->broadcastBoostRemove($boost);
        $this->em->detach($boost);

        return $this->head(204);
    }

    /**
     * `set_message`: the message among the rooms of the bot, by `message_id`; nil means
     * `head :not_found`.
     */
    private function setMessage(Request $request): ?Message
    {
        $params = $this->params($request);
        $roomId = RubyInteger::cast($params->get('room_id'));
        $messageId = RubyInteger::cast($params->get('message_id'));
        if (null === $roomId || null === $messageId) {
            return null;
        }
        $found = $this->connection->fetchOne(
            'SELECT messages.id FROM messages INNER JOIN memberships ON memberships.room_id = messages.room_id WHERE memberships.user_id = ? AND messages.room_id = ? AND messages.id = ? LIMIT 1',
            [$this->bot()->getId(), $roomId, $messageId],
        );

        return false === $found ? null : $this->em->find(Message::class, (int) $found);
    }

    /**
     * messages/boosts/_boost.json.jbuilder.
     *
     * @return array<string, mixed>
     */
    private function boostJson(Boost $boost): array
    {
        $message = $boost->getMessage();
        $booster = $boost->getBooster();

        return [
            'id' => $boost->getId(),
            'content' => $boost->getContent(),
            'created_at' => $boost->getCreatedAt(),
            'booster' => $this->userJson($booster),
            'message' => [
                'id' => $message->getId(),
                'url' => $this->urls->generate('room_message', ['room_id' => $message->getRoom()->getId(), 'id' => $message->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            ],
        ];
    }

    /**
     * users/_user.json.jbuilder.
     *
     * @return array<string, mixed>
     */
    private function userJson(User $user): array
    {
        return ['id' => $user->getId(), 'name' => $user->getName(), 'role' => $user->getRole()->railsName(), 'avatar_url' => $this->avatars->freshUserAvatarUrl($user)];
    }

    /**
     * `head` from a before_action: Rails has not set the request's formats yet there, so the
     * response is typed text/html (the action's own heads are typed by the format, JSON here).
     */
    private function halted(int $status): Response
    {
        return $this->head($status, ['Content-Type' => 'text/html']);
    }

    private function bot(): User
    {
        return $this->currentUser() ?? throw new \LogicException('Bot access without a current user');
    }
}
