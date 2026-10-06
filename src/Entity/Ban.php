<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** reference/app/models/ban.rb: an IP address a banned user signed in from. */
#[ORM\Entity(repositoryClass: BanRepository::class)]
#[ORM\Table(name: 'bans')]
final class Ban implements Timestamped
{
    use IdTrait;
    use TimestampsTrait;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
        private User $user,
        #[ORM\Column(name: 'ip_address', type: Types::STRING)]
        private string $ipAddress,
    ) {
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }
}
