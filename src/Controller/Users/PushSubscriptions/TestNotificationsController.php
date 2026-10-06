<?php

declare(strict_types=1);

namespace App\Controller\Users\PushSubscriptions;

use App\Controller\ApplicationController;
use App\Domain\Rooms\RubyInteger;
use App\Domain\Users\Push\PushSubscriptions;
use App\Entity\PushSubscription;
use App\Http\Exception\RecordNotFound;
use App\Repository\PushSubscriptionRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Users::PushSubscriptions::TestNotificationsController (reference/app/controllers/users/push_subscriptions/test_notifications_controller.rb).
 */
#[Route(defaults: ['_format' => null, 'user_id' => 'me'])]
final class TestNotificationsController extends ApplicationController
{
    public function __construct(
        private readonly PushSubscriptions $pushSubscriptions,
        private readonly PushSubscriptionRepository $repository,
    ) {
    }

    #[Route('/users/{user_id}/push_subscriptions/{push_subscription_id}/test_notifications.{_format}', name: 'user_push_subscription_test_notifications', methods: ['POST'], priority: 113)]
    public function create(Request $request): Response
    {
        // `set_push_subscription`: `Current.user.push_subscriptions.find(params[:push_subscription_id])`
        $id = RubyInteger::cast($request->attributes->get('push_subscription_id'));
        $subscription = null === $id ? null : $this->repository->find($id);
        if (!$subscription instanceof PushSubscription || $subscription->getUser()->getId() !== $this->currentUser()?->getId()) {
            throw RecordNotFound::for('Push::Subscription', $request->attributes->get('push_subscription_id'));
        }

        $url = $this->generateUrl('user_push_subscriptions', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->pushSubscriptions->deliver($subscription, 'Campfire Test', Uuid::v4()->toRfc4122(), $url);

        return $this->redirectTo($url);
    }
}
