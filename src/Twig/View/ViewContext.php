<?php

declare(strict_types=1);

namespace App\Twig\View;

use App\Entity\Account;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * The per-request state view helpers read (Rails' Current, flash, CSRF, Turbo stream signing),
 * behind one seam so the helpers can be unit-tested. RequestViewContext is the application's.
 */
interface ViewContext
{
    public function user(): ?User;

    public function account(): ?Account;

    public function request(): ?Request;

    /** `Current.account&.logo&.attached?` */
    public function accountHasLogo(): bool;

    /** The current request's flash (notice/alert). */
    public function flash(string $key): ?string;

    /** `request_forgery_protection_token` ("authenticity_token"). */
    public function csrfParam(): string;

    /** `form_authenticity_token` (masked, for the csrf-token meta tag). */
    public function csrfToken(): string;

    /** Per-form token: `form_authenticity_token(form_options: { action:, method: })`. */
    public function formCsrfToken(string $action, string $method): string;

    /** `Turbo::StreamsChannel.signed_stream_name(streamables)`.
     *
     * @param array<mixed> $streamables
     */
    public function signedStreamName(array $streamables): string;

    /** `user.avatar_token` (signed id, purpose :avatar). */
    public function avatarToken(User $user): string;

    /** The request's ApplicationPlatform (request attribute "campfire.platform"), if set. */
    public function platform(): ?object;
}
