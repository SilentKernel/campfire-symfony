<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Account;
use App\Entity\Session;
use App\Entity\User;
use App\Repository\AccountRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Rails' `Current` (reference/app/models/current.rb) plus the controller's `authenticated_by`
 * (reference/app/controllers/concerns/authentication.rb). Reset after every request.
 */
final class Current implements ResetInterface
{
    public const string BY_SESSION = 'session';
    public const string BY_BOT_KEY = 'bot_key';

    private ?User $user = null;
    private ?Session $session = null;
    private ?Request $request = null;
    private string $authenticatedBy = '';
    private ?Account $account = null;
    private bool $accountLoaded = false;

    public function __construct(private readonly AccountRepository $accounts)
    {
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function session(): ?Session
    {
        return $this->session;
    }

    /** Set by SetCurrentRequest (ApplicationController's chain only). */
    public function request(): ?Request
    {
        return $this->request;
    }

    /** `Account.first`, memoized for the request. */
    public function account(): ?Account
    {
        if (!$this->accountLoaded) {
            $this->account = $this->accounts->findSingleton();
            $this->accountLoaded = true;
        }

        return $this->account;
    }

    /** '' (nobody), 'session' or 'bot_key', like `authenticated_by` ("".inquiry). */
    public function authenticatedBy(): string
    {
        return $this->authenticatedBy;
    }

    public function isAuthenticatedByBotKey(): bool
    {
        return self::BY_BOT_KEY === $this->authenticatedBy;
    }

    /** `signed_in?` */
    public function isSignedIn(): bool
    {
        return null !== $this->user;
    }

    public function setUser(?User $user): void
    {
        $this->user = $user;
    }

    /** `Current.session=` also sets `Current.user` to the session's user. */
    public function setSession(?Session $session): void
    {
        $this->session = $session;
        if (null !== $session) {
            $this->user = $session->getUser();
        }
    }

    public function setRequest(?Request $request): void
    {
        $this->request = $request;
    }

    public function setAuthenticatedBy(string $method): void
    {
        $this->authenticatedBy = $method;
    }

    public function reset(): void
    {
        $this->user = null;
        $this->session = null;
        $this->request = null;
        $this->authenticatedBy = '';
        $this->account = null;
        $this->accountLoaded = false;
    }
}
