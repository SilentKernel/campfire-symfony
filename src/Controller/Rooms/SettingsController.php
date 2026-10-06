<?php

declare(strict_types=1);

namespace App\Controller\Rooms;

use App\Controller\ApplicationController;
use App\Http\Attribute\ActionNotFound;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rails routes rooms/settings#show but defines no Rooms::SettingsController: the request raises
 * ActionController::RoutingError (404) once matched, so the route must exist to shadow later ones.
 */
#[ActionNotFound]
#[Route(defaults: ['_format' => null])]
final class SettingsController extends ApplicationController
{
    #[Route('/rooms/{room_id}/settings.{_format}', name: 'room_settings', methods: ['GET'], priority: 86)]
    public function show(): never
    {
        throw new NotFoundHttpException('uninitialized constant Rooms::SettingsController');
    }
}
