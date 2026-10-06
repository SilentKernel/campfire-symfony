<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\Involvement;
use App\Repository\RoomRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * reference/app/models/room.rb, single-table inheritance on `type` like Rails' STI.
 *
 * Doctrine never changes a discriminator: converting an open room into a closed one (which Rails
 * does with `becomes!`) is an `UPDATE rooms SET type = …` through DBAL followed by a refresh.
 * Associations pointing at Room (not a leaf class) are always loaded eagerly by Doctrine, since it
 * needs the row's `type` to pick the class.
 */
#[ORM\Entity(repositoryClass: RoomRepository::class)]
#[ORM\Table(name: 'rooms')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: Types::STRING)]
#[ORM\DiscriminatorMap([Rooms\Open::TYPE => Rooms\Open::class, Rooms\Closed::TYPE => Rooms\Closed::class, Rooms\Direct::TYPE => Rooms\Direct::class])]
abstract class Room implements Timestamped
{
    use IdTrait;
    use TimestampsTrait;

    /** The STI class names Rails stores in `type`, by Doctrine class. */
    public const TYPES = [
        Rooms\Open::class => Rooms\Open::TYPE,
        Rooms\Closed::class => Rooms\Closed::TYPE,
        Rooms\Direct::class => Rooms\Direct::TYPE,
    ];

    #[ORM\Column(name: 'name', type: Types::STRING, nullable: true)]
    private ?string $name = null;

    /** @var Collection<int, Membership> */
    #[ORM\OneToMany(targetEntity: Membership::class, mappedBy: 'room', fetch: 'EXTRA_LAZY')]
    private Collection $memberships;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class, fetch: 'LAZY')]
        #[ORM\JoinColumn(name: 'creator_id', referencedColumnName: 'id', nullable: false)]
        private User $creator,
        ?string $name = null,
    ) {
        $this->name = $name;
        $this->memberships = new ArrayCollection();
    }

    /** The Rails class name stored in `type` ("Rooms::Open"). */
    abstract public function getType(): string;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCreator(): User
    {
        return $this->creator;
    }

    public function setCreator(User $creator): static
    {
        $this->creator = $creator;

        return $this;
    }

    /** @return Collection<int, Membership> */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    public function isOpen(): bool
    {
        return $this instanceof Rooms\Open;
    }

    public function isClosed(): bool
    {
        return $this instanceof Rooms\Closed;
    }

    public function isDirect(): bool
    {
        return $this instanceof Rooms\Direct;
    }

    /** The involvement new memberships get (`default_involvement`). */
    public function getDefaultInvolvement(): Involvement
    {
        return Involvement::Mentions;
    }
}
