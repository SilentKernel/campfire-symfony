<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Room;
use App\Entity\Rooms\Direct;
use App\Entity\Rooms\Open;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Room> */
final class RoomRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Room::class);
    }

    /** `Room.original`: `order(:created_at).first`, the room first run created. */
    public function findOriginal(): ?Room
    {
        return $this->createQueryBuilder('r')->orderBy('r.createdAt', 'ASC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /**
     * `Rooms::Open.pluck(:id)`.
     *
     * @return list<int>
     */
    public function findOpenIds(): array
    {
        $ids = $this->createQueryBuilder('r')->select('r.id')
            ->where(\sprintf('r INSTANCE OF %s', Open::class))
            ->orderBy('r.id', 'ASC')
            ->getQuery()->getSingleColumnResult();

        return array_values(array_map(intval(...), $ids));
    }

    /**
     * `Room.without_directs.ordered` (rooms an administrator sees), by LOWER(name).
     *
     * @return list<Room>
     */
    public function findWithoutDirectsOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->where(\sprintf('r NOT INSTANCE OF %s', Direct::class))
            ->orderBy('LOWER(r.name)', 'ASC')
            ->getQuery()->getResult();
    }
}
