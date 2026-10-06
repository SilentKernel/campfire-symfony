<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * reference/app/models/message.rb. The body is an Action Text record
 * (RichText: record_type "Message", name "body"), the attachment an Active Storage
 * attachment (record_type "Message", name "attachment"); neither is a Doctrine association.
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'messages')]
final class Message implements Timestamped
{
    use IdTrait;
    use TimestampsTrait;

    /** The polymorphic `record_type` of this model. */
    public const RECORD_TYPE = 'Message';

    /** @var Collection<int, Boost> */
    #[ORM\OneToMany(targetEntity: Boost::class, mappedBy: 'message', fetch: 'EXTRA_LAZY')]
    private Collection $boosts;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Room::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'room_id', referencedColumnName: 'id', nullable: false)]
        private Room $room,
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'creator_id', referencedColumnName: 'id', nullable: false)]
        private User $creator,
        #[ORM\Column(name: 'client_message_id', type: Types::STRING)]
        private string $clientMessageId,
    ) {
        $this->boosts = new ArrayCollection();
    }

    public function getRoom(): Room
    {
        return $this->room;
    }

    public function setRoom(Room $room): static
    {
        $this->room = $room;

        return $this;
    }

    public function getCreator(): User
    {
        return $this->creator;
    }

    public function getClientMessageId(): string
    {
        return $this->clientMessageId;
    }

    public function setClientMessageId(string $clientMessageId): static
    {
        $this->clientMessageId = $clientMessageId;

        return $this;
    }

    /** @return Collection<int, Boost> unordered, like `message.boosts` (use BoostRepository for `.ordered`) */
    public function getBoosts(): Collection
    {
        return $this->boosts;
    }

    /**
     * `to_key`: messages are identified by their client id in dom_id and cache keys.
     *
     * @return list<string>
     */
    public function toKey(): array
    {
        return [$this->clientMessageId];
    }
}
