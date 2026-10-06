<?php

declare(strict_types=1);

namespace App\Controller\Rails\Conductor\ActionMailbox\InboundEmails;

use App\Http\Attribute\NotApplicationController;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rails::Conductor::ActionMailbox::InboundEmails::SourcesController (Rails framework controller, not an ApplicationController).
 */
#[NotApplicationController]
#[Route(defaults: ['_format' => null])]
final class SourcesController extends AbstractController
{
    #[Route('/rails/conductor/action_mailbox/inbound_emails/sources/new.{_format}', name: 'new_rails_conductor_inbound_email_source', methods: ['GET'], priority: 13)]
    public function new(): Response
    {
        // Rails::Conductor::BaseController#ensure_development_env: production answers 403.
        return new Response('', Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    #[Route('/rails/conductor/action_mailbox/inbound_emails/sources.{_format}', name: 'rails_conductor_inbound_email_sources', methods: ['POST'], priority: 12)]
    public function create(): Response
    {
        // Rails::Conductor::BaseController#ensure_development_env: production answers 403.
        return new Response('', Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
