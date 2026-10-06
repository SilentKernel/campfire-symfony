<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PushSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Push::Subscription (reference/app/models/push/subscription.rb): a browser's Web Push endpoint. */
#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\Table(name: 'push_subscriptions')]
final class PushSubscription implements Timestamped
{
    use IdTrait;
    use TimestampsTrait;

    #[ORM\Column(name: 'endpoint', type: Types::STRING, nullable: true)]
    private ?string $endpoint = null;

    #[ORM\Column(name: 'p256dh_key', type: Types::STRING, nullable: true)]
    private ?string $p256dhKey = null;

    #[ORM\Column(name: 'auth_key', type: Types::STRING, nullable: true)]
    private ?string $authKey = null;

    #[ORM\Column(name: 'user_agent', type: Types::STRING, nullable: true)]
    private ?string $userAgent = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
        private User $user,
    ) {
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function setEndpoint(?string $endpoint): static
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    public function getP256dhKey(): ?string
    {
        return $this->p256dhKey;
    }

    public function setP256dhKey(?string $p256dhKey): static
    {
        $this->p256dhKey = $p256dhKey;

        return $this;
    }

    public function getAuthKey(): ?string
    {
        return $this->authKey;
    }

    public function setAuthKey(?string $authKey): static
    {
        $this->authKey = $authKey;

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
}
