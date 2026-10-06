<?php

declare(strict_types=1);

namespace App\Domain\Rooms;

use App\Entity\Membership;
use App\Entity\Room;
use App\Entity\Rooms\Direct;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * `user.rooms` (has_many :rooms, through: :memberships) and its scopes, as the controllers'
 * `room_scope` uses them.
 */
final readonly class UserRooms
{
    /** `Current.user.rooms` (RoomsController#room_scope) */
    public const string ALL = 'all';
    /** `Current.user.rooms.without_directs` (opens, closeds) */
    public const string WITHOUT_DIRECTS = 'without_directs';
    /** `Current.user.rooms.directs` (directs) */
    public const string DIRECTS = 'directs';

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** `user.rooms[.scope].find_by(id:)`; $id is the raw param, cast like Active Model. */
    public function find(User $user, mixed $id, string $scope = self::ALL): ?Room
    {
        $id = RubyInteger::cast($id);
        if (null === $id) {
            return null;
        }

        $qb = $this->rooms($user)->andWhere('r.id = :id')->setParameter('id', $id);
        if (self::WITHOUT_DIRECTS === $scope) {
            $qb->andWhere(\sprintf('r NOT INSTANCE OF %s', Direct::class));
        } elseif (self::DIRECTS === $scope) {
            $qb->andWhere(\sprintf('r INSTANCE OF %s', Direct::class));
        }

        return self::one($qb);
    }

    /** `user.rooms.last` (by primary key). */
    public function last(User $user): ?Room
    {
        return self::one($this->rooms($user)->orderBy('r.id', 'DESC'));
    }

    /** `user.rooms.original` (`order(:created_at).first`). */
    public function original(User $user): ?Room
    {
        return self::one($this->rooms($user)->orderBy('r.createdAt', 'ASC'));
    }

    /** `user.memberships.find_by(room_id:)` with its room (RoomScoped#set_room). */
    public function membership(User $user, mixed $roomId): ?Membership
    {
        $roomId = RubyInteger::cast($roomId);
        if (null === $roomId) {
            return null;
        }

        $membership = $this->em->createQueryBuilder()->select('m', 'r')->from(Membership::class, 'm')->join('m.room', 'r')
            ->where('m.user = :user')->andWhere('m.room = :room')
            ->setParameter('user', $user->getId())->setParameter('room', $roomId)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();

        return $membership instanceof Membership ? $membership : null;
    }

    private function rooms(User $user): QueryBuilder
    {
        return $this->em->createQueryBuilder()->select('r')->from(Room::class, 'r')
            ->join(Membership::class, 'm', 'WITH', 'm.room = r')
            ->where('m.user = :user')->setParameter('user', $user->getId());
    }

    private static function one(QueryBuilder $qb): ?Room
    {
        $room = $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();

        return $room instanceof Room ? $room : null;
    }
}
