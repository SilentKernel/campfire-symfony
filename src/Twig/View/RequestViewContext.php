<?php

declare(strict_types=1);

namespace App\Twig\View;

use App\Entity\Account;
use App\Entity\User;
use App\Http\Csrf;
use App\Http\Current;
use App\Http\Flash;
use App\Rails\SignedId;
use App\Rails\TurboStreamName;
use App\Twig\Html\TurboStream;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\ResetInterface;

final class RequestViewContext implements ViewContext, ResetInterface
{
    private ?bool $accountHasLogo = null;

    public function __construct(
        private readonly Current $current,
        private readonly Csrf $csrf,
        private readonly Flash $flash,
        private readonly TurboStreamName $turboStreamName,
        private readonly SignedId $signedId,
        private readonly Connection $connection,
    ) {
    }

    public function user(): ?User
    {
        return $this->current->user();
    }

    public function account(): ?Account
    {
        return $this->current->account();
    }

    public function request(): ?Request
    {
        return $this->current->request();
    }

    public function accountHasLogo(): bool
    {
        if (null === $account = $this->account()) {
            return false;
        }

        return $this->accountHasLogo ??= false !== $this->connection->fetchOne(
            "SELECT 1 FROM active_storage_attachments WHERE record_type = 'Account' AND record_id = ? AND name = 'logo' LIMIT 1",
            [$account->getId()],
        );
    }

    public function flash(string $key): ?string
    {
        return $this->flash->get($key);
    }

    public function csrfParam(): string
    {
        return $this->csrf->param();
    }

    public function csrfToken(): string
    {
        return $this->csrf->maskedToken();
    }

    public function formCsrfToken(string $action, string $method): string
    {
        return $this->csrf->formToken($action, $method);
    }

    /** @param array<mixed> $streamables */
    public function signedStreamName(array $streamables): string
    {
        return $this->turboStreamName->sign($this->turboStreamName->name(TurboStream::streamNameParts($streamables)));
    }

    public function avatarToken(User $user): string
    {
        // reference/app/models/user/avatar.rb: signed_id(purpose: :avatar)
        return $this->signedId->generate($user->getId(), 'User', 'avatar');
    }

    public function platform(): ?object
    {
        $platform = $this->request()?->attributes->get('campfire.platform');

        return \is_object($platform) ? $platform : null;
    }

    public function reset(): void
    {
        $this->accountHasLogo = null;
    }
}
