<?php

declare(strict_types=1);

namespace App\Controller\Messages;

use App\Controller\ApplicationController;
use App\Domain\Messages\Boosts;
use App\Entity\Message;
use App\Http\Attribute\ActionNotFound;
use App\Http\Exception\ParameterMissing;
use App\Http\Exception\RecordNotFound;
use App\Http\Exception\UnknownFormat;
use App\Http\Mime;
use App\Http\Params;
use App\View\MessageRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Messages::BoostsController (reference/app/controllers/messages/boosts_controller.rb).
 */
#[Route(defaults: ['_format' => null])]
final class BoostsController extends ApplicationController
{
    public function __construct(
        private readonly Boosts $boosts,
        private readonly MessageRenderer $renderer,
    ) {
    }

    #[Route('/messages/{message_id}/boosts.{_format}', name: 'message_boosts', methods: ['GET'], priority: 49)]
    public function index(Request $request): Response
    {
        $message = $this->setMessage($request);

        return $this->implicitHtml($request, fn (): Response => $this->render('messages/boosts/index.html.twig', [
            'boosts_html' => $this->renderer->renderBoosts($message),
        ]));
    }

    #[Route('/messages/{message_id}/boosts.{_format}', name: 'message_boosts.post', methods: ['POST'], priority: 48)]
    public function create(Request $request): Response
    {
        $message = $this->setMessage($request);
        $boost = $this->params($request)->require('boost');
        if (!$boost instanceof Params) {
            throw new ParameterMissing('boost');
        }
        $content = $boost->get('content');
        $user = $this->currentUser() ?? throw new \LogicException('Authenticated action without a user.');
        $this->boosts->create($message, $user, \is_scalar($content) ? (string) $content : '');

        return $this->redirectTo($this->generateUrl('message_boosts', ['message_id' => $message->getId()], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    #[Route('/messages/{message_id}/boosts/new.{_format}', name: 'new_message_boost', methods: ['GET'], priority: 47)]
    public function new(Request $request): Response
    {
        $message = $this->setMessage($request);

        return $this->implicitHtml($request, fn (): Response => $this->render('messages/boosts/new.html.twig', ['message' => $message]));
    }

    #[ActionNotFound]
    #[Route('/messages/{message_id}/boosts/{id}/edit.{_format}', name: 'edit_message_boost', methods: ['GET'], priority: 46)]
    public function edit(): never
    {
        throw new NotFoundHttpException("The action 'edit' could not be found for Messages::BoostsController");
    }

    #[ActionNotFound]
    #[Route('/messages/{message_id}/boosts/{id}.{_format}', name: 'message_boost', methods: ['GET'], priority: 45)]
    public function show(): never
    {
        throw new NotFoundHttpException("The action 'show' could not be found for Messages::BoostsController");
    }

    #[ActionNotFound]
    #[Route('/messages/{message_id}/boosts/{id}.{_format}', name: 'message_boost.patch', methods: ['PATCH'], priority: 44)]
    #[Route('/messages/{message_id}/boosts/{id}.{_format}', name: 'message_boost.put', methods: ['PUT'], priority: 43)]
    public function update(): never
    {
        throw new NotFoundHttpException("The action 'update' could not be found for Messages::BoostsController");
    }

    #[Route('/messages/{message_id}/boosts/{id}.{_format}', name: 'message_boost.delete', methods: ['DELETE'], priority: 42)]
    public function destroy(Request $request): Response
    {
        $message = $this->setMessage($request);
        $user = $this->currentUser() ?? throw new \LogicException('Authenticated action without a user.');
        $id = $this->params($request)->get('id');
        $boost = $this->boosts->findOwn($message, $user, $id) ?? throw RecordNotFound::for('Boost');

        $this->boosts->destroy($boost);

        // No destroy template: Rails answers a non-GET request with 204.
        return $this->head(Response::HTTP_NO_CONTENT);
    }

    /** `Current.user.reachable_messages.find(params[:message_id])` */
    private function setMessage(Request $request): Message
    {
        $user = $this->currentUser() ?? throw RecordNotFound::for('Message');
        $id = $this->params($request)->get('message_id');

        return $this->boosts->findReachableMessage($user, $id) ?? throw RecordNotFound::for('Message', $id);
    }

    /** @param callable(): Response $render */
    private function implicitHtml(Request $request, callable $render): Response
    {
        if (null === Mime::negotiate($request, ['html'])) {
            throw new UnknownFormat();
        }
        $response = $render();
        if (Mime::shouldApplyVaryHeader($request)) {
            $response->headers->set('Vary', 'Accept');
        }

        return $response;
    }
}
