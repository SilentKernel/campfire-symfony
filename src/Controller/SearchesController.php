<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Messages\Searches;
use App\Domain\Rooms\TrackedRoomVisit;
use App\Entity\Message;
use App\Entity\User;
use App\Http\Exception\UnknownFormat;
use App\Http\Mime;
use App\Http\Params;
use App\Storage\Paths;
use App\View\MessageRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * SearchesController (reference/app/controllers/searches_controller.rb). `set_messages` runs
 * before every action, so a query FTS5 rejects fails create and clear too, as in Rails.
 */
#[Route(defaults: ['_format' => null])]
final class SearchesController extends ApplicationController
{
    public function __construct(
        private readonly Searches $searches,
        private readonly MessageRenderer $renderer,
        private readonly TrackedRoomVisit $trackedRoomVisit,
    ) {
    }

    #[Route('/searches/clear.{_format}', name: 'clear_searches', methods: ['DELETE'], priority: 33)]
    public function clear(Request $request): Response
    {
        $user = $this->user();
        $this->setMessages($request, $user);
        $this->searches->clear($user);

        return $this->redirectTo($this->generateUrl('searches', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }

    #[Route('/searches.{_format}', name: 'searches', methods: ['GET'], priority: 32)]
    public function index(Request $request): Response
    {
        $user = $this->user();
        $messages = $this->setMessages($request, $user);
        $query = $this->query($request);
        if (null === Mime::negotiate($request, ['html'])) {
            throw new UnknownFormat();
        }

        $messagesHtml = $this->renderer->render($messages);
        $response = $this->render('searches/index.html.twig', [
            'query' => null !== $query && !Params::isBlank($query) ? $query : null,
            'q' => $this->q($request),
            'messages_count' => \count($messages),
            // A block whose output is only whitespace captures as the block's value ("\n").
            'messages_html' => '' === $messagesHtml ? "\n" : "\n    ".$messagesHtml."\n",
            'recent_searches' => $this->searches->recent($user),
            'return_to_room' => $this->trackedRoomVisit->lastRoomVisited(),
        ]);
        if (Mime::shouldApplyVaryHeader($request)) {
            $response->headers->set('Vary', 'Accept');
        }

        return $response;
    }

    #[Route('/searches.{_format}', name: 'searches.post', methods: ['POST'], priority: 31)]
    public function create(Request $request): Response
    {
        $user = $this->user();
        $this->setMessages($request, $user);
        // Search.record(nil) violates searches.query's NOT NULL constraint.
        $query = $this->query($request) ?? throw new \RuntimeException('NOT NULL constraint failed: searches.query');
        $this->searches->record($user, $query);

        return $this->redirectTo($this->generateUrl('searches', [], UrlGeneratorInterface::ABSOLUTE_URL).'?q='.Paths::cgiEscape($query));
    }

    /**
     * `set_messages`: the newest 100 messages matching the query among the user's rooms.
     *
     * @return list<Message>
     */
    private function setMessages(Request $request, User $user): array
    {
        $query = $this->query($request);

        return null !== $query && !Params::isBlank($query) ? $this->searches->search($user, $query) : [];
    }

    /** `params[:q]&.gsub(/[^[:word:]]/, " ")`; anything but a string makes gsub raise. */
    private function query(Request $request): ?string
    {
        return Searches::sanitize($this->q($request));
    }

    private function q(Request $request): ?string
    {
        $q = $this->params($request)->get('q');
        if (null !== $q && !\is_string($q)) {
            throw new \RuntimeException("undefined method 'gsub'");
        }

        return $q;
    }

    private function user(): User
    {
        return $this->currentUser() ?? throw new \LogicException('Authenticated action without a user.');
    }
}
