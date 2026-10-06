<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BoostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** reference/app/models/boost.rb */
#[ORM\Entity(repositoryClass: BoostRepository::class)]
#[ORM\Table(name: 'boosts')]
final class Boost implements Timestamped
{
    use IdTrait;
    use TimestampsTrait;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Message::class, fetch: 'LAZY', inversedBy: 'boosts')]
        #[ORM\JoinColumn(name: 'message_id', referencedColumnName: 'id', nullable: false)]
        private Message $message,
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'booster_id', referencedColumnName: 'id', nullable: false)]
        private User $booster,
        #[ORM\Column(name: 'content', type: Types::STRING, length: 16)]
        private string $content,
    ) {
    }

    public function getMessage(): Message
    {
        return $this->message;
    }

    public function getBooster(): User
    {
        return $this->booster;
    }

    public function getContent(): string
    {
        return $this->content;
    }
}
