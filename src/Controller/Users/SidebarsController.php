<?php

declare(strict_types=1);

namespace App\Controller\Users;

use App\Controller\ApplicationController;
use App\Domain\Rooms\Sidebar;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Users::SidebarsController (reference/app/controllers/users/sidebars_controller.rb). The
 * `user_id` segment is ignored: the sidebar is always Current.user's.
 */
#[Route(defaults: ['_format' => null, 'user_id' => 'me'])]
final class SidebarsController extends ApplicationController
{
    public function __construct(private readonly Sidebar $sidebar)
    {
    }

    #[Route('/users/{user_id}/sidebar.{_format}', name: 'user_sidebar', methods: ['GET'], priority: 121)]
    public function show(Request $request): Response
    {
        $user = $this->currentUser() ?? throw new \LogicException('No current user.');
        [$directRooms, $sharedRooms] = $this->sidebar->memberships($user);
        $placeholders = $this->sidebar->directPlaceholderUsers($user);

        return $this->respondTo($request, ['html' => fn (): Response => $this->render('users/sidebars/show.html.twig', [
            'direct_rooms' => $directRooms,
            'shared_rooms' => $sharedRooms,
            'direct_placeholder_users' => $placeholders,
            'can_create_rooms' => $user->isAdministrator() || true !== $this->current()->account()?->restrictsRoomCreationToAdministrators(),
        ])]);
    }
}
