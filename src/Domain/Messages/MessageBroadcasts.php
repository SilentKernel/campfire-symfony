<?php

declare(strict_types=1);

namespace App\Domain\Messages;

use App\Cable\Broadcaster;
use App\Cable\StreamNames;
use App\Entity\Boost;
use App\Entity\Message;
use App\Entity\Room;
use App\Twig\Html\RecordIdentifier;
use App\Twig\Html\TurboStream;
use App\View\MessageRenderer;
use Doctrine\DBAL\Connection;
use Twig\Markup;

/**
 * Message::Broadcasts (reference/app/models/message/broadcasts.rb) and the other Turbo stream
 * broadcasts of messages and boosts (MessagesController#update, Messages::BoostsController),
 * on the room's `[room, :messages]` stream. Partials are rendered detached, as
 * ApplicationController.renderer renders them.
 */
final readonly class MessageBroadcasts
{
    public function __construct(
        private Broadcaster $broadcaster,
        private MessageRenderer $renderer,
        private Connection $connection,
    ) {
    }

    /**
     * `broadcast_create`: the message appended to the room's messages, then the unread ping for
     * each member. Returns the rendered partial (the fragment the cache now holds).
     */
    public function broadcastCreate(Message $message): string
    {
        $room = $message->getRoom();
        $html = $this->renderer->renderDetached($message);
        $this->broadcaster->broadcast(StreamNames::roomMessages($room), TurboStream::action('append', RecordIdentifier::domId($room, 'messages'), new Markup($html, 'UTF-8')));
        $this->broadcastUnreadRoom($room);

        return $html;
    }

    /**
     * `broadcast_unread_room`: fanned out to the room's members rather than published on one
     * global stream, so the timing of activity in a room only reaches people who are in it.
     */
    public function broadcastUnreadRoom(Room $room): void
    {
        $userIds = $this->connection->fetchFirstColumn('SELECT user_id FROM memberships WHERE room_id = ?', [$room->getId()]);
        foreach ($userIds as $userId) {
            $this->broadcaster->broadcast(StreamNames::unreadRooms((int) $userId), ['roomId' => $room->getId()]);
        }
    }

    /** `broadcast_remove`: `broadcast_remove_to room, :messages`. */
    public function broadcastRemove(Message $message): void
    {
        $this->broadcaster->broadcast(StreamNames::roomMessages($message->getRoom()), TurboStream::actionTag('remove', RecordIdentifier::domId($message)));
    }

    /**
     * `broadcast_replace_to @room, :messages, target: [ @message, :presentation ], partial:
     * "messages/presentation", attributes: { maintain_scroll: true }`
     */
    public function broadcastReplace(Message $message): void
    {
        $this->broadcaster->broadcast(StreamNames::roomMessages($message->getRoom()), TurboStream::actionTag(
            'replace',
            RecordIdentifier::domId($message, 'presentation'),
            template: new Markup($this->renderer->renderPresentation($message), 'UTF-8'),
            attributes: ['maintain_scroll' => true],
        ));
    }

    /**
     * Messages::BoostsController#broadcast_create: the boost appended to
     * "boosts_message_<client id>", maintaining scroll.
     */
    public function broadcastBoostCreate(Boost $boost): void
    {
        $message = $boost->getMessage();
        $this->broadcaster->broadcast(StreamNames::roomMessages($message->getRoom()), TurboStream::actionTag(
            'append',
            'boosts_message_'.$message->getClientMessageId(),
            template: new Markup($this->renderer->renderBoost($boost), 'UTF-8'),
            attributes: ['maintain_scroll' => true],
        ));
    }

    /** Messages::BoostsController#broadcast_remove */
    public function broadcastBoostRemove(Boost $boost): void
    {
        $this->broadcaster->broadcast(StreamNames::roomMessages($boost->getMessage()->getRoom()), TurboStream::actionTag('remove', RecordIdentifier::domId($boost)));
    }
}
