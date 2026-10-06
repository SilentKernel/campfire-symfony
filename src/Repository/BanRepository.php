<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Ban;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Ban> */
final class BanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ban::class);
    }

    /** `Ban.banned?(ip_address)` */
    public function isBanned(string $ipAddress): bool
    {
        return null !== $this->createQueryBuilder('b')->select('b.id')
            ->where('b.ipAddress = :ip')->setParameter('ip', $ipAddress)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** @return list<Ban> */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['id' => 'ASC']);
    }
}
