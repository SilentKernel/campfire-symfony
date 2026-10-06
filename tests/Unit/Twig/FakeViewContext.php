<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\Account;
use App\Entity\User;
use App\Rails\SignedId;
use App\Rails\TurboStreamName;
use App\Twig\Html\TurboStream;
use App\Twig\View\ViewContext;
use Symfony\Component\HttpFoundation\Request;

/** A ViewContext with fixed state; form tokens are "token:<method>:<action>". */
final class FakeViewContext implements ViewContext
{
    /** @var list<array<mixed>> */
    public array $signedStreams = [];

    /** @param array<string, string> $flash */
    public function __construct(
        public ?User $user = null,
        public ?Account $account = null,
        public ?Request $request = null,
        public array $flash = [],
        public bool $accountHasLogo = false,
        public ?object $platform = null,
        public ?TurboStreamName $streamNames = null,
        public ?SignedId $signedIds = null,
    ) {
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function account(): ?Account
    {
        return $this->account;
    }

    public function request(): ?Request
    {
        return $this->request;
    }

    public function accountHasLogo(): bool
    {
        return $this->accountHasLogo;
    }

    public function flash(string $key): ?string
    {
        return $this->flash[$key] ?? null;
    }

    public function csrfParam(): string
    {
        return 'authenticity_token';
    }

    public function csrfToken(): string
    {
        return 'masked-token';
    }

    public function formCsrfToken(string $action, string $method): string
    {
        return 'token:'.$method.':'.$action;
    }

    public function signedStreamName(array $streamables): string
    {
        $this->signedStreams[] = $streamables;

        return null !== $this->streamNames
            ? $this->streamNames->sign($this->streamNames->name(TurboStream::streamNameParts($streamables)))
            : 'signed--name';
    }

    public function avatarToken(User $user): string
    {
        return $this->signedIds?->generate($user->getId(), 'User', 'avatar') ?? 'avatar-token-'.$user->getId();
    }

    public function platform(): ?object
    {
        return $this->platform;
    }
}
