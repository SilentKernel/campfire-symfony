<?php

declare(strict_types=1);

namespace App\Controller\ActionMailbox\Ingresses\Mailgun;

use App\Http\Attribute\NotApplicationController;
use App\Http\Attribute\SkipForgeryProtection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ActionMailbox::Ingresses::Mailgun::InboundEmailsController (Rails framework controller, not an ApplicationController).
 */
#[NotApplicationController]
#[SkipForgeryProtection]
#[Route(defaults: ['_format' => null])]
final class InboundEmailsController extends AbstractController
{
    #[Route('/rails/action_mailbox/mailgun/inbound_emails/mime.{_format}', name: 'rails_mailgun_inbound_emails', methods: ['POST'], priority: 18)]
    public function create(): Response
    {
        // ActionMailbox::BaseController#ensure_configured: Campfire configures no ingress.
        return new Response('', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
