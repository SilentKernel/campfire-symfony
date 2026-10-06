<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Messages\MessageCreator;
use App\Domain\Messages\MessageDestroyer;
use App\Domain\Messages\MessagePages;
use App\Domain\Messages\MessageRichText;
use App\Domain\Messages\MessageUpdater;
use App\Domain\Rooms\RubyInteger;
use App\Domain\Rooms\UserRooms;
use App\Entity\ActiveStorage\Blob;
use App\Entity\Message;
use App\Entity\Room;
use App\Http\Attribute\ActionNotFound;
use App\Http\Exception\RecordNotFound;
use App\Http\Exception\UnknownFormat;
use App\Http\Mime;
use App\Http\Params;
use App\Http\RackResponseHeaderBag;
use App\Rails\AppVerifiers;
use App\View\MessageRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * MessagesController (reference/app/controllers/messages_controller.rb). RoomScoped#set_room
 * runs for every action but create, which looks the room up itself and renders room_not_found
 * when it is gone.
 */
#[Route(defaults: ['_format' => null])]
final class MessagesController extends ApplicationController
{
    public function __construct(
        private readonly UserRooms $userRooms,
        private readonly MessagePages $pages,
        private readonly MessageRenderer $renderer,
        private readonly MessageCreator $creator,
        private readonly MessageUpdater $updater,
        private readonly MessageDestroyer $destroyer,
        private readonly MessageRichText $richText,
        private readonly AppVerifiers $verifiers,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/rooms/{room_id}/messages.{_format}', name: 'room_messages', methods: ['GET'], priority: 102)]
    #[Route('/messages.{_format}', name: 'messages', methods: ['GET'], priority: 41)]
    public function index(Request $request): Response
    {
        $room = $this->setRoom($request);
        $messages = $this->findPagedMessages($request, $room);
        if ([] === $messages) {
            return $this->head(Response::HTTP_NO_CONTENT);
        }

        // fresh_when @messages: a weak ETag of the records' cache keys (with the template, as
        // ETagWithTemplateDigest adds it) and their latest updated_at.
        $keys = [];
        $lastModified = null;
        foreach ($messages as $message) {
            $keys[] = 'messages/'.$message->getId().'-'.$message->getUpdatedAt()->format('YmdHisu');
            $lastModified = null === $lastModified || $message->getUpdatedAt() > $lastModified ? $message->getUpdatedAt() : $lastModified;
        }
        $etag = \sprintf('W/"%s"', substr(hash('sha256', 'messages/index:'.MessageRenderer::VERSION.'/'.implode('/', $keys)), 0, 32));
        $headers = [
            'ETag' => $etag,
            'Last-Modified' => $lastModified->setTimezone(new \DateTimeZone('UTC'))->format('D, d M Y H:i:s').' GMT',
            'Cache-Control' => 'max-age=0, private, must-revalidate',
        ];
        if (self::isFresh($request, $etag, $lastModified)) {
            return self::withRackHeaders(new Response('', Response::HTTP_NOT_MODIFIED), $headers);
        }

        // `layout false`
        return self::withRackHeaders($this->implicitRender($request, ['html'], fn (): Response => $this->html($this->renderView('messages/index.html.twig', [
            'messages_html' => $this->renderer->render($messages),
        ]))), $headers);
    }

    #[Route('/rooms/{room_id}/messages.{_format}', name: 'room_messages.post', methods: ['POST'], priority: 101)]
    #[Route('/messages.{_format}', name: 'messages.post', methods: ['POST'], priority: 40)]
    public function create(Request $request): Response
    {
        try {
            $room = $this->setRoom($request);
        } catch (NotFoundHttpException) {
            return $this->roomNotFound($request);
        }

        $params = $this->messageParams($request);
        $user = $this->currentUser() ?? throw new \LogicException('Authenticated action without a user.');
        $message = $this->creator->create(
            $room,
            $user,
            self::stringParam($params, 'body'),
            $this->attachmentParam($params),
            self::stringParam($params, 'client_message_id'),
        );

        // create.turbo_stream: the partial comes out of the cache broadcast_create just filled.
        return $this->implicitRender($request, ['turbo_stream'], fn (): Response => $this->turboStream($this->renderView('messages/create.turbo_stream.twig', [
            'message' => $message,
            'message_html' => $this->renderer->renderOne($message),
        ])));
    }

    #[ActionNotFound]
    #[Route('/rooms/{room_id}/messages/new.{_format}', name: 'new_room_message', methods: ['GET'], priority: 100)]
    #[Route('/messages/new.{_format}', name: 'new_message', methods: ['GET'], priority: 39)]
    public function new(): never
    {
        throw new NotFoundHttpException("The action 'new' could not be found for MessagesController");
    }

    #[Route('/rooms/{room_id}/messages/{id}/edit.{_format}', name: 'edit_room_message', methods: ['GET'], priority: 99)]
    #[Route('/messages/{id}/edit.{_format}', name: 'edit_message', methods: ['GET'], priority: 38)]
    public function edit(Request $request): Response
    {
        $room = $this->setRoom($request);
        $message = $this->setMessage($request, $room);
        $this->ensureCanAdminister($message);

        return $this->implicitRender($request, ['html'], function () use ($message, $room): Response {
            $presentation = $this->renderer->presentation($message);

            return $this->render('messages/edit.html.twig', [
                'p' => $presentation,
                'room' => $room,
                'editable_body' => $this->richText->forEditor($presentation->body),
            ]);
        });
    }

    #[Route('/rooms/{room_id}/messages/{id}.{_format}', name: 'room_message', methods: ['GET'], priority: 98)]
    #[Route('/messages/{id}.{_format}', name: 'message', methods: ['GET'], priority: 37)]
    public function show(Request $request): Response
    {
        $room = $this->setRoom($request);
        $message = $this->setMessage($request, $room);

        return $this->implicitRender($request, ['html'], fn (): Response => $this->render('messages/show.html.twig', [
            'message_html' => $this->renderer->renderOne($message),
        ]));
    }

    #[Route('/rooms/{room_id}/messages/{id}.{_format}', name: 'room_message.patch', methods: ['PATCH'], priority: 97)]
    #[Route('/rooms/{room_id}/messages/{id}.{_format}', name: 'room_message.put', methods: ['PUT'], priority: 96)]
    #[Route('/messages/{id}.{_format}', name: 'message.patch', methods: ['PATCH'], priority: 36)]
    #[Route('/messages/{id}.{_format}', name: 'message.put', methods: ['PUT'], priority: 35)]
    public function update(Request $request): Response
    {
        $room = $this->setRoom($request);
        $message = $this->setMessage($request, $room);
        $this->ensureCanAdminister($message);

        $params = $this->messageParams($request);
        $body = self::stringParam($params, 'body');
        if (null !== $body) {
            $this->updater->update($message, $body);
        } else {
            $this->updater->broadcastReplace($message);
        }

        return $this->respondTo($request, [
            'html' => fn (): Response => $this->redirectTo($this->generateUrl('room_message', ['room_id' => $room->getId(), 'id' => $message->getId()], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)),
            // `render :show`: MessagesController has no show.json template.
            'json' => static fn (): Response => throw new \RuntimeException('Missing template messages/show'),
        ]);
    }

    #[Route('/rooms/{room_id}/messages/{id}.{_format}', name: 'room_message.delete', methods: ['DELETE'], priority: 95)]
    #[Route('/messages/{id}.{_format}', name: 'message.delete', methods: ['DELETE'], priority: 34)]
    public function destroy(Request $request): Response
    {
        $room = $this->setRoom($request);
        $message = $this->setMessage($request, $room);
        $this->ensureCanAdminister($message);

        $this->destroyer->destroy($message);
        $this->destroyer->broadcastRemove($message);

        return $this->implicitRender($request, ['turbo_stream'], fn (): Response => $this->turboStream($this->renderView('messages/destroy.turbo_stream.twig', ['message' => $message])));
    }

    /** RoomScoped#set_room: `Current.user.memberships.find_by!(room_id: params[:room_id])`. */
    private function setRoom(Request $request): Room
    {
        $user = $this->currentUser() ?? throw RecordNotFound::for('Membership');
        $membership = $this->userRooms->membership($user, $this->params($request)->get('room_id'));

        return $membership?->getRoom() ?? throw RecordNotFound::for('Membership');
    }

    /** `@room.messages.find(params[:id])` */
    private function setMessage(Request $request, Room $room): Message
    {
        $id = $this->params($request)->get('id');

        return $this->pages->find($room, RubyInteger::cast($id)) ?? throw RecordNotFound::for('Message', $id);
    }

    /** `head :forbidden unless Current.user.can_administer?(@message)` */
    private function ensureCanAdminister(Message $message): void
    {
        if (true !== $this->currentUser()?->canAdminister($message)) {
            $this->halt($this->head(Response::HTTP_FORBIDDEN));
        }
    }

    /**
     * find_paged_messages: the page before or after a message of the room, or the last page.
     *
     * @return list<Message>
     */
    private function findPagedMessages(Request $request, Room $room): array
    {
        $params = $this->params($request);
        foreach (['before', 'after'] as $key) {
            $value = $params->get($key);
            if (!Params::isBlank($value)) {
                $anchor = $this->pages->find($room, RubyInteger::cast($value)) ?? throw RecordNotFound::for('Message', $value);

                return 'before' === $key ? $this->pages->pageBefore($room, $anchor) : $this->pages->pageAfter($room, $anchor);
            }
        }

        return $this->pages->lastPage($room);
    }

    /**
     * `params.require(:message).permit(:body, :attachment, :client_message_id)`.
     *
     * @return array<string, mixed>
     */
    private function messageParams(Request $request): array
    {
        $message = $this->params($request)->require('message');
        if (!$message instanceof Params) {
            // A scalar passes `require` but `permit` raises on it.
            throw new \App\Http\Exception\ParameterMissing('message');
        }
        $permitted = [];
        foreach (['body', 'client_message_id'] as $key) {
            $value = $message->get($key);
            if (\is_string($value) || \is_int($value) || \is_float($value) || \is_bool($value) || null === $value && $message->has($key)) {
                $permitted[$key] = $value;
            }
        }
        $attachment = $request->files->all('message')['attachment'] ?? $message->get('attachment');
        if ($attachment instanceof UploadedFile || \is_string($attachment)) {
            $permitted['attachment'] = $attachment;
        }

        return $permitted;
    }

    /** @param array<string, mixed> $params */
    private static function stringParam(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;

        return null === $value ? null : (\is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
    }

    /**
     * An uploaded file, or a signed blob id (a direct upload); "" attaches nothing.
     *
     * @param array<string, mixed> $params
     */
    private function attachmentParam(array $params): UploadedFile|Blob|null
    {
        $attachment = $params['attachment'] ?? null;
        if ($attachment instanceof UploadedFile) {
            if (!$attachment->isValid()) {
                throw new \RuntimeException($attachment->getErrorMessage());
            }

            return $attachment;
        }
        if (!\is_string($attachment) || '' === $attachment) {
            return null;
        }
        $blobId = $this->verifiers->verifier('ActiveStorage')->verified($attachment, 'blob_id', $this->clock->now());

        return (\is_int($blobId) ? $this->em->find(Blob::class, $blobId) : null)
            ?? throw new \RuntimeException('ActiveSupport::MessageVerifier::InvalidSignature');
    }

    /** `render action: :room_not_found` (HTML, in the application layout). */
    private function roomNotFound(Request $request): Response
    {
        if (null === Mime::negotiate($request, ['turbo_stream', 'html'])) {
            throw new \RuntimeException('Missing template messages/room_not_found');
        }
        $response = $this->render('messages/room_not_found.html.twig');
        if (Mime::shouldApplyVaryHeader($request)) {
            $response->headers->set('Vary', 'Accept');
        }

        return $response;
    }

    /**
     * An action's implicit render: the template of the first acceptable format, or 406 when the
     * request accepts none (ActionController::MissingExactTemplate); `Vary: Accept` when the
     * Accept header chose.
     *
     * @param list<string>          $formats
     * @param callable(): Response  $render
     * @param array<string, string> $headers
     */
    private function implicitRender(Request $request, array $formats, callable $render, array $headers = []): Response
    {
        if (null === Mime::negotiate($request, $formats)) {
            throw new UnknownFormat();
        }
        $response = $render();
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }
        if (Mime::shouldApplyVaryHeader($request) && !$response->headers->has('Vary')) {
            $response->headers->set('Vary', 'Accept');
        }

        return $response;
    }

    /**
     * Headers set as Rails sends them (Cache-Control kept as written rather than normalized).
     *
     * @param array<string, string> $headers
     */
    private static function withRackHeaders(Response $response, array $headers): Response
    {
        $bag = RackResponseHeaderBag::from($response->headers, $headers['Cache-Control'] ?? null);
        foreach ($headers as $name => $value) {
            if ('Cache-Control' !== $name) {
                $bag->set($name, $value);
            }
        }
        $response->headers = $bag;

        return $response;
    }

    /** `request.fresh?(response)`: If-None-Match and If-Modified-Since must both hold when given. */
    private static function isFresh(Request $request, string $etag, \DateTimeImmutable $lastModified): bool
    {
        $noneMatch = $request->headers->get('If-None-Match');
        $modifiedSince = $request->headers->get('If-Modified-Since');
        if (null === $noneMatch && null === $modifiedSince) {
            return false;
        }
        $fresh = true;
        if (null !== $modifiedSince) {
            $since = \DateTimeImmutable::createFromFormat(\DATE_RFC7231, $modifiedSince, new \DateTimeZone('UTC'));
            $fresh = false !== $since && $lastModified->getTimestamp() <= $since->getTimestamp();
        }
        if (null !== $noneMatch) {
            $etags = preg_split('/\s*,\s*/', trim($noneMatch)) ?: [];
            $fresh = $fresh && (\in_array($etag, $etags, true) || \in_array('*', $etags, true));
        }

        return $fresh;
    }
}
