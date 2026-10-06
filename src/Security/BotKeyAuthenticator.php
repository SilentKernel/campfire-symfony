<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Http\Current;
use App\Http\Params;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * `bot_authentication`: `params[:bot_key].present?` and `User.authenticate_bot(bot_key.strip)`
 * (an active bot). Only tried by require_authentication, after the session cookie failed.
 */
final class BotKeyAuthenticator extends AbstractAuthenticator
{
    private const string BOT_ATTRIBUTE = '_campfire_bot';

    public function __construct(
        private readonly Authentication $authentication,
        private readonly Current $current,
        private readonly UserRepository $users,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if (Params::isBlank(Params::fromRequest($request)->get('bot_key'))) {
            return false;
        }

        return match (Authentication::mode($request)) {
            null => null,
            Authentication::MODE_REQUIRE => !$this->current->isSignedIn(),
            default => false,
        };
    }

    public function authenticate(Request $request): Passport
    {
        $botKey = Params::fromRequest($request)->get('bot_key');
        if (!\is_string($botKey)) {
            // `params[:bot_key].strip` on a hash or array: NoMethodError, a 500.
            throw new \UnexpectedValueException("undefined method 'strip' for bot_key");
        }

        // Ruby's String#strip: whitespace and NUL.
        $bot = $this->users->authenticateBot(trim($botKey, " \t\n\v\f\r\0"))
            ?? throw new BadCredentialsException('Invalid bot key.');
        $request->attributes->set(self::BOT_ATTRIBUTE, $bot);

        return new SelfValidatingPassport(new UserBadge((string) $bot->getId(), static fn () => $bot));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $bot = $request->attributes->get(self::BOT_ATTRIBUTE);
        $request->attributes->remove(self::BOT_ATTRIBUTE);
        if ($bot instanceof User) {
            $this->authentication->authenticatedAsBot($bot);
        }

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }
}
