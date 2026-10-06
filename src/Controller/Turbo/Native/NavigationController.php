<?php

declare(strict_types=1);

namespace App\Controller\Turbo\Native;

use App\Http\Attribute\NotApplicationController;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Turbo::Native::NavigationController (turbo-rails app/controllers/turbo/native/navigation_controller.rb).
 */
#[NotApplicationController]
#[Route(defaults: ['_format' => null])]
final class NavigationController extends AbstractController
{
    #[Route('/recede_historical_location.{_format}', name: 'turbo_recede_historical_location', methods: ['GET'], priority: 26)]
    public function recede(): Response
    {
        return new Response('Going back…', Response::HTTP_OK, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    #[Route('/resume_historical_location.{_format}', name: 'turbo_resume_historical_location', methods: ['GET'], priority: 25)]
    public function resume(): Response
    {
        return new Response('Staying put…', Response::HTTP_OK, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    #[Route('/refresh_historical_location.{_format}', name: 'turbo_refresh_historical_location', methods: ['GET'], priority: 24)]
    public function refresh(): Response
    {
        return new Response('Refreshing…', Response::HTTP_OK, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
