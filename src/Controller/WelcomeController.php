<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Rooms\TrackedRoomVisit;
use App\Http\Concerns\ImplicitRender;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * WelcomeController (reference/app/controllers/welcome_controller.rb).
 */
#[Route(defaults: ['_format' => null])]
final class WelcomeController extends ApplicationController
{
    use ImplicitRender;

    public function __construct(
        private readonly TrackedRoomVisit $trackedRoomVisit,
        private readonly Connection $connection,
    ) {
    }

    #[Route('/', name: 'root', methods: ['GET'], priority: 177)]
    public function show(Request $request): Response
    {
        $user = $this->currentUser();
        \assert(null !== $user);
        // `Current.user.rooms.any?`
        $anyRoom = false !== $this->connection->fetchOne('SELECT 1 AS one FROM "rooms" INNER JOIN "memberships" ON "rooms"."id" = "memberships"."room_id" WHERE "memberships"."user_id" = ? LIMIT 1', [$user->getId()]);
        if ($anyRoom && null !== $room = $this->trackedRoomVisit->lastRoomVisited()) {
            return $this->redirectTo($this->generateUrl('room', ['id' => $room->getId()], UrlGeneratorInterface::ABSOLUTE_URL));
        }

        return $this->renderHtml($request, 'welcome/show.html.twig');
    }
}
