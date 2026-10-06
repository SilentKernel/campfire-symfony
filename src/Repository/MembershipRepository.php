<?php

declare(strict_types=1);

namespace App\Repository;

use App\Database\Type\RailsDateTimeType;
use App\Entity\Enum\Involvement;
use App\Entity\Membership;
use App\Entity\Room;
use App\Entity\Rooms\Direct;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Membership> */
final class MembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Membership::class);
    }

    /** `room.memberships.find_by(user:)` / `user.memberships.find_by(room:)` */
    public function findOneFor(Room|int $room, User|int $user): ?Membership
    {
        return $this->findOneBy(['room' => $room, 'user' => $user]);
    }

    /**
     * `user.memberships.visible.with_ordered_room`: the sidebar's rooms, by LOWER(rooms.name).
     *
     * @return list<Membership>
     */
    public function findVisibleWithOrderedRoom(User $user): array
    {
        return $this->visible($this->createQueryBuilder('m'))
            ->addSelect('r')->join('m.room', 'r')
            ->andWhere('m.user = :user')->setParameter('user', $user)
            ->orderBy('LOWER(r.name)', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * `room.memberships` with their users, in insertion order.
     *
     * @return list<Membership>
     */
    public function findForRoom(Room $room): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('u')->join('m.user', 'u')
            ->where('m.room = :room')->setParameter('room', $room)
            ->orderBy('m.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * `user.memberships`, rooms preloaded.
     *
     * @return list<Membership>
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('r')->join('m.room', 'r')
            ->where('m.user = :user')->setParameter('user', $user)
            ->orderBy('m.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * `room.memberships.visible.connected`: users connected within CONNECTION_TTL of $now.
     *
     * @return list<Membership>
     */
    public function findConnectedForRoom(Room $room, \DateTimeImmutable $now): array
    {
        return $this->visible($this->createQueryBuilder('m'))
            ->andWhere('m.room = :room')->setParameter('room', $room)
            ->andWhere('m.connectedAt >= :since')
            ->setParameter('since', $now->modify(\sprintf('-%d seconds', Membership::CONNECTION_TTL)), RailsDateTimeType::NAME)
            ->getQuery()->getResult();
    }

    /** `memberships.without_direct_rooms`: joins the room and excludes Rooms::Direct. */
    public function withoutDirectRooms(QueryBuilder $qb, string $alias = 'm'): QueryBuilder
    {
        return $qb->join($alias.'.room', $alias.'_room')
            ->andWhere(\sprintf('%s_room NOT INSTANCE OF %s', $alias, Direct::class));
    }

    /**
     * `visible`: `where.not(involvement: :invisible)`, which in SQL also leaves out NULL involvement
     * (`involvement != 'invisible'` is NULL for NULL).
     */
    public function visible(QueryBuilder $qb, string $alias = 'm'): QueryBuilder
    {
        return $qb->andWhere(\sprintf("%s.involvement != '%s'", $alias, Involvement::Invisible->value));
    }
}
