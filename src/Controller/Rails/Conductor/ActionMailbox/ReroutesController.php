<?php

declare(strict_types=1);

namespace App\Controller\Rails\Conductor\ActionMailbox;

use App\Http\Attribute\NotApplicationController;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rails::Conductor::ActionMailbox::ReroutesController (Rails framework controller, not an ApplicationController).
 */
#[NotApplicationController]
#[Route(defaults: ['_format' => null])]
final class ReroutesController extends AbstractController
{
    #[Route('/rails/conductor/action_mailbox/{inbound_email_id}/reroute.{_format}', name: 'rails_conductor_inbound_email_reroute', methods: ['POST'], priority: 11)]
    public function create(): Response
    {
        // Rails::Conductor::BaseController#ensure_development_env: production answers 403.
        return new Response('', Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
