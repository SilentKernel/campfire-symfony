<?php

declare(strict_types=1);

namespace App\Entity;

use App\Database\Type\RailsDateTimeType;
use App\Repository\SessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** reference/app/models/session.rb: a signed-in browser, found by the `session_token` cookie. */
#[ORM\Entity(repositoryClass: SessionRepository::class)]
#[ORM\Table(name: 'sessions')]
final class Session implements Timestamped
{
    use IdTrait;
    use TimestampsTrait;

    /** Session::ACTIVITY_REFRESH_RATE, in seconds. */
    public const ACTIVITY_REFRESH_RATE = 3600;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
        private User $user,
        /** `has_secure_token`: 24 base58 characters. */
        #[ORM\Column(name: 'token', type: Types::STRING)]
        private string $token,
        /** `before_create { self.last_active_at ||= Time.now }` */
        #[ORM\Column(name: 'last_active_at', type: RailsDateTimeType::NAME)]
        private \DateTimeImmutable $lastActiveAt,
        #[ORM\Column(name: 'user_agent', type: Types::STRING, nullable: true)]
        private ?string $userAgent = null,
        #[ORM\Column(name: 'ip_address', type: Types::STRING, nullable: true)]
        private ?string $ipAddress = null,
    ) {
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getLastActiveAt(): \DateTimeImmutable
    {
        return $this->lastActiveAt;
    }

    public function setLastActiveAt(\DateTimeImmutable $lastActiveAt): static
    {
        if ($this->lastActiveAt != $lastActiveAt) {
            $this->lastActiveAt = $lastActiveAt;
        }

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    /** `resume` refreshes the session only when `last_active_at.before?(ACTIVITY_REFRESH_RATE.ago)`. */
    public function isActivityStale(\DateTimeImmutable $now): bool
    {
        return $this->lastActiveAt < $now->modify(\sprintf('-%d seconds', self::ACTIVITY_REFRESH_RATE));
    }
}
