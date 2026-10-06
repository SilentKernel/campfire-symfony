<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WebhookRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** reference/app/models/webhook.rb: a bot's endpoint (`has_one :webhook`, see WebhookRepository). */
#[ORM\Entity(repositoryClass: WebhookRepository::class)]
#[ORM\Table(name: 'webhooks')]
final class Webhook implements Timestamped
{
    use IdTrait;
    use TimestampsTrait;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
        private User $user,
        #[ORM\Column(name: 'url', type: Types::STRING, nullable: true)]
        private ?string $url = null,
    ) {
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;

        return $this;
    }
}
