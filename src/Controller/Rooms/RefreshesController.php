<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\Controller\ApplicationController;
use App\Domain\Messages\MessagePages;
use App\Domain\Rooms\RubyInteger;
use App\Domain\Rooms\UserRooms;
use App\Entity\Room;
use App\Http\Exception\RecordNotFound;
use App\Twig\Html\RecordIdentifier;
use App\Twig\Html\TurboStream;
use App\View\MessageRenderer;
use Doctrine\DBAL\ArrayParameterType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rooms::RefreshesController (reference/app/controllers/rooms/refreshes_controller.rb): the
 * messages created or updated since the client last loaded the room, as Turbo Streams
 * (rooms/refreshes/show.turbo_stream.erb).
 */
#[Route(defaults: ['_format' => null])]
final class RefreshesController extends ApplicationController
{
    public function __construct(
        private readonly UserRooms $userRooms,
        private readonly MessagePages $messages,
        private readonly MessageRenderer $renderer,
    ) {
    }

    #[Route('/rooms/{room_id}/refresh.{_format}', name: 'room_refresh', methods: ['GET'], priority: 87)]
    public function show(Request $request): Response
    {
        $room = $this->setRoom($request);
        $lastUpdatedAt = self::lastUpdatedAt($this->params($request)->get('since'));

        return $this->respondTo($request, ['turbo_stream' => fn (): Response => $this->turboStream($this->streams($room, $lastUpdatedAt))]);
    }

    private function streams(Room $room, \DateTimeImmutable $lastUpdatedAt): string
    {
        $newMessages = $this->messages->pageCreatedSince($room, $lastUpdatedAt);
        // `@room.messages.without(@new_messages).page_updated_since(@last_updated_at)`
        $updated = $this->messages->scope($room)->andWhere('m.updatedAt > :since')->setParameter('since', $lastUpdatedAt, \App\Database\Type\RailsDateTimeType::NAME);
        if ([] !== $newMessages) {
            $updated->andWhere('m.id NOT IN (:new)')->setParameter('new', array_map(static fn (\App\Entity\Message $m): int => $m->getId(), $newMessages), ArrayParameterType::INTEGER);
        }
        $updatedMessages = $this->messages->lastPageOf($updated, MessagePages::PAGE_SIZE);

        // Byte for byte as Erubi renders the view: the append (its `<% end if %>` line is trimmed),
        // the blank line between the two blocks (so an empty refresh is "\n", which Rack::ETag
        // digests), then one indented line per replace.
        $html = '';
        if ([] !== $newMessages) {
            $html .= TurboStream::action('append', RecordIdentifier::domId($room, 'messages'), "\n".$this->renderer->render($newMessages));
        }
        $html .= "\n";
        foreach ($updatedMessages as $message) {
            $html .= '  '.TurboStream::action('replace', RecordIdentifier::domId($message), $this->renderer->renderOne($message))."\n";
        }

        return $html;
    }

    /** RoomScoped#set_room */
    private function setRoom(Request $request): Room
    {
        $roomId = $this->params($request)->get('room_id');
        $user = $this->currentUser() ?? throw new \LogicException('No current user.');

        return $this->userRooms->membership($user, $roomId)?->getRoom() ?? throw RecordNotFound::for('Membership', \is_scalar($roomId) ? (string) $roomId : null);
    }

    /** `Time.at(0, params[:since].to_i, :millisecond)` (clamped to the dates PHP and SQLite can hold). */
    private static function lastUpdatedAt(mixed $since): \DateTimeImmutable
    {
        if (null !== $since && !\is_string($since)) {
            throw new \LogicException(\sprintf("undefined method 'to_i' for an instance of %s", get_debug_type($since)));
        }
        $milliseconds = RubyInteger::cast(null === $since ? '0' : RubyInteger::toI($since)) ?? (str_starts_with(RubyInteger::toI((string) $since), '-') ? \PHP_INT_MIN : \PHP_INT_MAX);
        $milliseconds = max(-62135596800000, min(253402300799999, $milliseconds));
        $seconds = intdiv($milliseconds, 1000);
        $remainder = $milliseconds - $seconds * 1000;
        if ($remainder < 0) {
            --$seconds;
            $remainder += 1000;
        }

        return (new \DateTimeImmutable('@'.$seconds))->modify(\sprintf('+%d milliseconds', $remainder));
    }
}
