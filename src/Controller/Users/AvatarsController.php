<?php

declare(strict_types=1);

namespace App\Controller\Users;

use App\Controller\ApplicationController;
use App\Entity\User;
use App\Http\Exception\RecordNotFound;
use App\Http\Mime;
use App\Rails\SignedId;
use App\Repository\UserRepository;
use App\Storage\Attachments;
use App\Storage\DiskService;
use App\Storage\Http\AppAssets;
use App\Storage\Http\Responses;
use App\Storage\Representations;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Users::AvatarsController (reference/app/controllers/users/avatars_controller.rb): a user's avatar
 * by its signed avatar token: the uploaded image's :square variant, the default bot avatar, or an
 * SVG of their initials.
 */
#[Route(defaults: ['_format' => null])]
final class AvatarsController extends ApplicationController
{
    /**
     * `ActionView::Digestor.digest(name: "users/avatars/show")` of the reference's show.svg.erb,
     * which EtagWithTemplateDigest adds when the template is found for the request's formats.
     */
    public const string TEMPLATE_DIGEST = 'd500db55e2a67222018ef0156839c3c9';

    /** `expires_in 30.minutes, public: true, stale_while_revalidate: 1.week` */
    private const int MAX_AGE = 30 * 60;
    private const int STALE_WHILE_REVALIDATE = 7 * 24 * 60 * 60;

    public function __construct(
        private readonly SignedId $signedId,
        private readonly UserRepository $users,
        private readonly Representations $representations,
        private readonly Attachments $attachments,
        private readonly DiskService $disk,
        private readonly AppAssets $assets,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/users/{user_id}/avatar.{_format}', name: 'user_avatar', methods: ['GET'], priority: 125)]
    public function show(Request $request): Response
    {
        $user = $this->fromAvatarToken($request);
        if (null === $user) {
            // rescue_from(ActiveSupport::MessageVerifier::InvalidSignature) { head :not_found }
            return Responses::head($request, 404);
        }

        // stale?(etag: @user)
        $validators = [Responses::cacheKeyWithVersion('users', $user->getId(), $user->getUpdatedAt())];
        if ($request->headers->has('Turbo-Frame')) {
            $validators[] = 'frame';
        }
        if (self::templateFound($request)) {
            $validators[] = self::TEMPLATE_DIGEST;
        }
        $etag = Responses::weakEtag($validators);
        if (Responses::isFresh($request, $etag)) {
            return new Response('', 304, ['ETag' => $etag]);
        }

        if (null !== $variant = $this->representations->processedNamedVariant('User', $user->getId(), 'avatar', 'square')) {
            $response = Responses::sendFile($request, $this->disk->pathFor($variant->getKey()), 'image/webp');
        } elseif ($user->isBot()) {
            $response = Responses::sendFile($request, $this->assets->image('default-bot-avatar.svg'), 'image/svg+xml');
        } else {
            // render formats: :svg
            $response = new Response($this->renderView('users/avatars/show.svg.twig', ['user' => $user]), 200, ['Content-Type' => 'image/svg+xml; charset=utf-8']);
            if (Mime::shouldApplyVaryHeader($request)) {
                $response->headers->set('Vary', 'Accept');
            }
        }
        $response->headers->set('ETag', $etag);
        Responses::expiresIn($response, self::MAX_AGE, public: true, staleWhileRevalidate: self::STALE_WHILE_REVALIDATE);

        return $response;
    }

    #[Route('/users/{user_id}/avatar.{_format}', name: 'user_avatar.delete', methods: ['DELETE'], priority: 124)]
    public function destroy(): Response
    {
        $user = $this->currentUser() ?? throw new \LogicException('require_authentication lets no anonymous request through.');
        $this->attachments->detach('User', $user->getId(), 'avatar');

        return $this->redirectTo($this->generateUrl('user_profile'));
    }

    /** `User.from_avatar_token(params[:user_id])`: `find_signed!(sid, purpose: :avatar)`. */
    private function fromAvatarToken(Request $request): ?User
    {
        $token = $request->attributes->get('user_id');
        $id = \is_string($token) ? $this->signedId->find($token, 'User', 'avatar', $this->clock->now()) : null;
        if (null === $id) {
            return null;
        }

        return $this->users->find($id) ?? throw RecordNotFound::for('User', $id);
    }

    /**
     * Whether `lookup_context.find_all("show", ["users/avatars", ...])` finds show.svg.erb for the
     * request's formats: any or svg, or no registered format at all.
     */
    private static function templateFound(Request $request): bool
    {
        $formats = Mime::formats($request);

        return [] === $formats || \in_array(Mime::ALL, $formats, true) || \in_array('svg', $formats, true);
    }
}
