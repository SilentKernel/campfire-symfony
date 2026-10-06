<?php

declare(strict_types=1);

namespace App\Entity;

use App\Database\Type\RailsDateTimeType;
use App\Entity\Enum\Involvement;
use App\Repository\MembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** reference/app/models/membership.rb and membership/connectable.rb. */
#[ORM\Entity(repositoryClass: MembershipRepository::class)]
#[ORM\Table(name: 'memberships')]
final class Membership implements Timestamped
{
    use IdTrait;
    use TimestampsTrait;

    /** Membership::Connectable::CONNECTION_TTL, in seconds. */
    public const CONNECTION_TTL = 60;

    /** The column is nullable; a NULL involvement is neither visible nor any `involved_in_*?`. */
    #[ORM\Column(name: 'involvement', type: Types::STRING, nullable: true, enumType: Involvement::class)]
    private ?Involvement $involvement = Involvement::Mentions;

    #[ORM\Column(name: 'connections', type: Types::INTEGER)]
    private int $connections = 0;

    #[ORM\Column(name: 'connected_at', type: RailsDateTimeType::NAME, nullable: true)]
    private ?\DateTimeImmutable $connectedAt = null;

    #[ORM\Column(name: 'unread_at', type: RailsDateTimeType::NAME, nullable: true)]
    private ?\DateTimeImmutable $unreadAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Room::class, fetch: 'LAZY', inversedBy: 'memberships')]
        #[ORM\JoinColumn(name: 'room_id', referencedColumnName: 'id', nullable: false)]
        private Room $room,
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY', inversedBy: 'memberships')]
        #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
        private User $user,
        ?Involvement $involvement = Involvement::Mentions,
    ) {
        $this->involvement = $involvement;
    }

    public function getRoom(): Room
    {
        return $this->room;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getInvolvement(): ?Involvement
    {
        return $this->involvement;
    }

    public function setInvolvement(?Involvement $involvement): static
    {
        $this->involvement = $involvement;

        return $this;
    }

    /** `involved_in_mentions?` and friends. */
    public function isInvolvedIn(Involvement $involvement): bool
    {
        return $involvement === $this->involvement;
    }

    public function getConnections(): int
    {
        return $this->connections;
    }

    public function setConnections(int $connections): static
    {
        $this->connections = $connections;

        return $this;
    }

    public function getConnectedAt(): ?\DateTimeImmutable
    {
        return $this->connectedAt;
    }

    public function setConnectedAt(?\DateTimeImmutable $connectedAt): static
    {
        if ($this->connectedAt != $connectedAt) {
            $this->connectedAt = $connectedAt;
        }

        return $this;
    }

    public function getUnreadAt(): ?\DateTimeImmutable
    {
        return $this->unreadAt;
    }

    public function setUnreadAt(?\DateTimeImmutable $unreadAt): static
    {
        if ($this->unreadAt != $unreadAt) {
            $this->unreadAt = $unreadAt;
        }

        return $this;
    }

    /** `unread?` */
    public function isUnread(): bool
    {
        return null !== $this->unreadAt;
    }

    /** `connected?`: connected_at within CONNECTION_TTL of $now. */
    public function isConnected(\DateTimeImmutable $now): bool
    {
        return null !== $this->connectedAt
            && $this->connectedAt >= $now->modify(\sprintf('-%d seconds', self::CONNECTION_TTL));
    }
}
