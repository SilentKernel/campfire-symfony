<?php

declare(strict_types=1);

namespace App\Controller\Messages;

use App\Cable\Server\RubyInteger;
use App\Controller\ApplicationController;
use App\Domain\Messages\MessageBroadcasts;
use App\Domain\Messages\MessageCreator;
use App\Domain\Messages\MessageDestroyer;
use App\Domain\Messages\MessagePages;
use App\Domain\Messages\MessageRichText;
use App\Domain\Messages\MessageUpdater;
use App\Entity\ActiveStorage\Blob;
use App\Entity\Message;
use App\Entity\Room;
use App\Entity\User;
use App\Http\Attribute\AllowBotAccess;
use App\Http\Exception\RecordNotFound;
use App\Rails\RailsJson;
use App\RichText\RichTextRenderer;
use App\Storage\Attachments;
use App\Storage\BlobService;
use App\Storage\Filename;
use App\Storage\StorageUrls;
use App\Twig\AvatarsExtension;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Messages::ByBotsController (reference/app/controllers/messages/by_bots_controller.rb): the bot
 * API, authenticated by the bot key in the path (JSON by route default). A message's body is the
 * raw request body (RawRequestBody), or a multipart `attachment`. In Rails it subclasses
 * MessagesController, whose create/update/destroy it extends or inherits.
 */
#[Route(defaults: ['_format' => 'json'])]
final class ByBotsController extends ApplicationController
{
    public const string JSON = 'application/json; charset=utf-8';

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly MessagePages $pages,
        private readonly MessageCreator $creator,
        private readonly MessageUpdater $updater,
        private readonly MessageDestroyer $destroyer,
        private readonly MessageBroadcasts $broadcasts,
        private readonly MessageRichText $richText,
        private readonly RichTextRenderer $renderer,
        private readonly Attachments $attachments,
        private readonly BlobService $blobs,
        private readonly StorageUrls $storageUrls,
        private readonly AvatarsExtension $avatars,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    #[AllowBotAccess]
    #[Route('/rooms/{room_id}/{bot_key}/messages.{_format}', name: 'room_bot_messages', methods: ['GET'], priority: 92)]
    public function index(Request $request): Response
    {
        $room = $this->setRoom($request);
        if ($room instanceof Response) {
            return $room;
        }
        $params = $this->params($request);
        $before = $params->get('before');
        $after = $params->get('after');

        // MessagesController#find_paged_messages
        $messages = match (true) {
            !self::isBlank($before) => $this->pages->pageBefore($room, $this->findMessage($room, $before)),
            !self::isBlank($after) => $this->pages->pageAfter($room, $this->findMessage($room, $after)),
            default => $this->pages->lastPage($room),
        };

        $headers = ['X-Total-Count' => (string) $this->pages->count($room)];
        $next = $this->nextPageParams($room, $messages, !self::isBlank($after));
        if (null !== $next) {
            $url = $this->urls->generate('room_bot_messages', ['room_id' => $room->getId(), 'bot_key' => (string) $params->get('bot_key')] + $next, UrlGeneratorInterface::ABSOLUTE_URL);
            $headers['Link'] = '<'.$url.'>; rel="next"';
        }

        return $this->jsonResponse(RailsJson::encode($this->messagesJson($messages)), 200, $headers);
    }

    #[AllowBotAccess]
    #[Route('/rooms/{room_id}/{bot_key}/messages.{_format}', name: 'room_bot_messages.post', methods: ['POST'], priority: 91)]
    public function create(Request $request): Response
    {
        $room = $this->setRoom($request);
        if ($room instanceof Response) {
            return $room;
        }
        $attachment = $this->params($request)->get('attachment');
        // ensure_body_or_attachment_present
        if (self::isBlank($attachment) && self::isBlank(self::rawRequestBody($request))) {
            return $this->halted(422);
        }

        // MessagesController#create: create_with_attachment!, broadcast_create, deliver_webhooks_to_bots
        $user = $this->bot();
        $message = null !== $attachment
            ? $this->creator->create($room, $user, null, $this->attachable($attachment))
            : $this->creator->create($room, $user, self::rawRequestBody($request));

        return $this->head(201, ['Location' => $this->urls->generate('message', ['id' => $message->getId()], UrlGeneratorInterface::ABSOLUTE_URL)]);
    }

    /** Rails: inherited from MessagesController#update, rendering `messages/by_bots/show`. */
    #[AllowBotAccess]
    #[Route('/rooms/{room_id}/{bot_key}/messages/{id}.{_format}', name: 'room_bot_message', methods: ['PATCH'], priority: 90)]
    #[Route('/rooms/{room_id}/{bot_key}/messages/{id}.{_format}', name: 'room_bot_message.put', methods: ['PUT'], priority: 89)]
    public function update(Request $request): Response
    {
        $room = $this->setRoom($request);
        if ($room instanceof Response) {
            return $room;
        }
        $message = $this->findMessage($room, $this->params($request)->get('id'));
        if (!$this->bot()->canAdminister($message)) {
            return $this->halted(403);
        }

        $attachment = $this->params($request)->get('attachment');
        if (null !== $attachment) {
            // `@message.update!(attachment:)`
            $blob = $this->attachable($attachment);
            $this->attachments->attach(Message::RECORD_TYPE, $message->getId(), 'attachment', $blob instanceof Blob ? $blob : $this->blobs->createFromUpload($blob));
            $this->broadcasts->broadcastReplace($message);
        } else {
            $this->updater->update($message, self::rawRequestBody($request));
        }

        return $this->jsonResponse(RailsJson::encode($this->messagesJson([$message])[0]));
    }

    /** Rails: MessagesController#destroy, then `head :no_content`. */
    #[AllowBotAccess]
    #[Route('/rooms/{room_id}/{bot_key}/messages/{id}.{_format}', name: 'room_bot_message.delete', methods: ['DELETE'], priority: 88)]
    public function destroy(Request $request): Response
    {
        $room = $this->setRoom($request);
        if ($room instanceof Response) {
            return $room;
        }
        $message = $this->findMessage($room, $this->params($request)->get('id'));
        if (!$this->bot()->canAdminister($message)) {
            return $this->halted(403);
        }
        $this->destroyer->destroy($message);
        $this->destroyer->broadcastRemove($message);

        return $this->head(204);
    }

    /** `set_room`: `Current.user.rooms.find_by(id: params[:room_id])`, else `head :not_found`. */
    private function setRoom(Request $request): Room|Response
    {
        $roomId = RubyInteger::cast($this->params($request)->get('room_id'));
        $found = null === $roomId ? false : $this->connection->fetchOne(
            'SELECT rooms.id FROM rooms INNER JOIN memberships ON rooms.id = memberships.room_id WHERE memberships.user_id = ? AND rooms.id = ? LIMIT 1',
            [$this->bot()->getId(), $roomId],
        );
        $room = false === $found ? null : $this->em->find(Room::class, (int) $found);

        return $room ?? $this->halted(404);
    }

    /** `@room.messages.find(id)`: RecordNotFound (404) when it is not in the room. */
    private function findMessage(Room $room, mixed $id): Message
    {
        return $this->pages->find($room, RubyInteger::cast($id)) ?? throw RecordNotFound::for('Message', $id);
    }

    /**
     * `next_page_params`.
     *
     * @param list<Message> $messages
     *
     * @return array{after: int}|array{before: int}|null
     */
    private function nextPageParams(Room $room, array $messages, bool $after): ?array
    {
        if ([] === $messages) {
            return null;
        }
        if ($after) {
            $last = $messages[\count($messages) - 1];

            return [] !== $this->pages->firstPageOf($this->pages->after($this->pages->scope($room), $last), 1) ? ['after' => $last->getId()] : null;
        }
        $first = $messages[0];

        return [] !== $this->pages->firstPageOf($this->pages->before($this->pages->scope($room), $first), 1) ? ['before' => $first->getId()] : null;
    }

    /**
     * messages/_message.json.jbuilder for each message.
     *
     * @param list<Message> $messages
     *
     * @return list<array<string, mixed>>
     */
    private function messagesJson(array $messages): array
    {
        $ids = array_map(static fn (Message $message): int => $message->getId(), $messages);
        $bodies = [] === $ids ? [] : $this->connection->fetchAllKeyValue(
            "SELECT record_id, body FROM action_text_rich_texts WHERE record_type = 'Message' AND name = 'body' AND record_id IN (?)",
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        $attachments = $this->attachments->forRecords(Message::RECORD_TYPE, $ids, 'attachment');

        $json = [];
        foreach ($messages as $message) {
            $id = $message->getId();
            $body = isset($bodies[$id]) ? (string) $bodies[$id] : null;
            $attachment = $attachments[$id] ?? null;
            $json[] = [
                'id' => $id,
                'created_at' => $message->getCreatedAt(),
                'body' => [
                    'plain_text' => $this->plainTextBody($body, null === $attachment ? null : $attachment->getBlob()->getFilename()),
                    // `message.body.to_s`: the rendered content in its layout
                    'html' => $this->renderer->toRenderedHtmlWithLayout($body),
                ],
                'creator' => $this->userJson($message->getCreator()),
                'room' => ['id' => $message->getRoom()->getId()],
                'url' => $this->urls->generate('room_message', ['room_id' => $message->getRoom()->getId(), 'id' => $id], UrlGeneratorInterface::ABSOLUTE_URL),
            ];
        }

        return $json;
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

    /** `plain_text_body`: the body's plain text, else the attachment's filename, else "". */
    private function plainTextBody(?string $body, ?string $filename): string
    {
        $text = null === $body ? '' : $this->richText->toPlainText($body);
        if (1 !== preg_match('/\A[[:space:]]*\z/u', $text)) {
            return $text;
        }

        return null === $filename ? '' : new Filename($filename)->sanitized();
    }

    /** `params.permit(:attachment)` as Active Storage takes it: an upload, or a blob's signed id. */
    private function attachable(mixed $attachment): UploadedFile|Blob
    {
        if ($attachment instanceof UploadedFile) {
            return $attachment;
        }
        $blobId = \is_string($attachment) ? $this->storageUrls->verifySignedId($attachment) : null;
        $blob = null === $blobId ? null : $this->em->find(Blob::class, $blobId);

        return $blob ?? throw new BadRequestHttpException('Invalid attachment');
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

    /** @param array<string, string> $headers */
    private function jsonResponse(string $body, int $status = 200, array $headers = []): Response
    {
        return new Response($body, $status, ['Content-Type' => self::JSON] + $headers);
    }

    /** RawRequestBody#raw_request_body */
    public static function rawRequestBody(Request $request): string
    {
        return $request->getContent();
    }

    /** Object#blank? for a param: nil, false, empty, or a whitespace-only string. */
    public static function isBlank(mixed $value): bool
    {
        return match (true) {
            null === $value, false === $value, [] === $value => true,
            \is_string($value) => 1 === preg_match('/\A[[:space:]]*\z/u', $value),
            default => false,
        };
    }
}
