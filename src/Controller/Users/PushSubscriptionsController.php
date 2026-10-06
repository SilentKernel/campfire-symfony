<?php

declare(strict_types=1);

namespace App\Controller\Users;

use App\Controller\ApplicationController;
use App\Domain\Rooms\TrackedRoomVisit;
use App\Domain\Users\Push\PushSubscriptions;
use App\Http\Attribute\ActionNotFound;
use App\Http\Concerns\ImplicitRender;
use App\Http\Concerns\PermitsParams;
use App\Http\Platform\UserAgent;
use App\Repository\PushSubscriptionRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Users::PushSubscriptionsController (reference/app/controllers/users/push_subscriptions_controller.rb).
 */
#[Route(defaults: ['_format' => null, 'user_id' => 'me'])]
final class PushSubscriptionsController extends ApplicationController
{
    use ImplicitRender;
    use PermitsParams;

    public function __construct(
        private readonly PushSubscriptions $pushSubscriptions,
        private readonly PushSubscriptionRepository $repository,
        private readonly TrackedRoomVisit $trackedRoomVisit,
    ) {
    }

    #[Route('/users/{user_id}/push_subscriptions.{_format}', name: 'user_push_subscriptions', methods: ['GET'], priority: 112)]
    public function index(Request $request): Response
    {
        $subscriptions = [];
        foreach ($this->repository->findForUser($this->currentUser() ?? throw new \LogicException('Not signed in')) as $subscription) {
            $agent = UserAgent::parse($subscription->getUserAgent());
            $subscriptions[] = [
                'record' => $subscription,
                // `"#{agent.browser} #{agent.version} on #{agent.platform}"`
                'agent' => \sprintf('%s %s on %s', $agent->browser(), $agent->version(), $agent->platform()),
            ];
        }

        return $this->renderHtml($request, 'users/push_subscriptions/index.html.twig', [
            'push_subscriptions' => $subscriptions,
            'last_room' => $this->trackedRoomVisit->lastRoomVisited(),
        ]);
    }

    #[Route('/users/{user_id}/push_subscriptions.{_format}', name: 'user_push_subscriptions.post', methods: ['POST'], priority: 111)]
    public function create(Request $request): Response
    {
        $user = $this->currentUser() ?? throw new \LogicException('Not signed in');
        $params = $this->requirePermitted($request, 'push_subscription', 'endpoint', 'p256dh_key', 'auth_key');

        if (null !== $subscription = $this->pushSubscriptions->findBy($user, $params)) {
            // Existing endpoints must pass current validations
            if (!$this->pushSubscriptions->isValid($subscription)) {
                return $this->head(Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->pushSubscriptions->touch($subscription);

            return $this->head(Response::HTTP_OK);
        }

        $subscription = $this->pushSubscriptions->create($user, $params, $request->headers->get('User-Agent'));

        return $this->head(null !== $subscription ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[ActionNotFound]
    #[Route('/users/{user_id}/push_subscriptions/new.{_format}', name: 'new_user_push_subscription', methods: ['GET'], priority: 110)]
    public function new(): never
    {
        throw new NotFoundHttpException("The action 'new' could not be found for Users::PushSubscriptionsController");
    }

    #[ActionNotFound]
    #[Route('/users/{user_id}/push_subscriptions/{id}/edit.{_format}', name: 'edit_user_push_subscription', methods: ['GET'], priority: 109)]
    public function edit(): never
    {
        throw new NotFoundHttpException("The action 'edit' could not be found for Users::PushSubscriptionsController");
    }

    #[ActionNotFound]
    #[Route('/users/{user_id}/push_subscriptions/{id}.{_format}', name: 'user_push_subscription', methods: ['GET'], priority: 108)]
    public function show(): never
    {
        throw new NotFoundHttpException("The action 'show' could not be found for Users::PushSubscriptionsController");
    }

    #[ActionNotFound]
    #[Route('/users/{user_id}/push_subscriptions/{id}.{_format}', name: 'user_push_subscription.patch', methods: ['PATCH'], priority: 107)]
    #[Route('/users/{user_id}/push_subscriptions/{id}.{_format}', name: 'user_push_subscription.put', methods: ['PUT'], priority: 106)]
    public function update(): never
    {
        throw new NotFoundHttpException("The action 'update' could not be found for Users::PushSubscriptionsController");
    }

    #[Route('/users/{user_id}/push_subscriptions/{id}.{_format}', name: 'user_push_subscription.delete', methods: ['DELETE'], priority: 105)]
    public function destroy(Request $request): Response
    {
        $this->pushSubscriptions->destroyBy($this->currentUser() ?? throw new \LogicException('Not signed in'), $request->attributes->get('id'));

        return $this->redirectTo($this->generateUrl('user_push_subscriptions', [], UrlGeneratorInterface::ABSOLUTE_URL));
    }
}
