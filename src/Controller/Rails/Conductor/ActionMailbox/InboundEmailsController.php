<?php

declare(strict_types=1);

namespace App\Controller\Rails\Conductor\ActionMailbox;

use App\Http\Attribute\NotApplicationController;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rails::Conductor::ActionMailbox::InboundEmailsController (Rails framework controller, not an ApplicationController).
 */
#[NotApplicationController]
#[Route(defaults: ['_format' => null])]
final class InboundEmailsController extends AbstractController
{
    #[Route('/rails/conductor/action_mailbox/inbound_emails.{_format}', name: 'rails_conductor_inbound_emails', methods: ['GET'], priority: 17)]
    public function index(): Response
    {
        // Rails::Conductor::BaseController#ensure_development_env: production answers 403.
        return new Response('', Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    #[Route('/rails/conductor/action_mailbox/inbound_emails.{_format}', name: 'rails_conductor_inbound_emails.post', methods: ['POST'], priority: 16)]
    public function create(): Response
    {
        // Rails::Conductor::BaseController#ensure_development_env: production answers 403.
        return new Response('', Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    #[Route('/rails/conductor/action_mailbox/inbound_emails/new.{_format}', name: 'new_rails_conductor_inbound_email', methods: ['GET'], priority: 15)]
    public function new(): Response
    {
        // Rails::Conductor::BaseController#ensure_development_env: production answers 403.
        return new Response('', Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    #[Route('/rails/conductor/action_mailbox/inbound_emails/{id}.{_format}', name: 'rails_conductor_inbound_email', methods: ['GET'], priority: 14)]
    public function show(): Response
    {
        // Rails::Conductor::BaseController#ensure_development_env: production answers 403.
        return new Response('', Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
