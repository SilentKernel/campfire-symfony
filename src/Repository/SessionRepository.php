<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Session;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Session> */
final class SessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Session::class);
    }

    /** `Session.find_by(token:)`, with its user. */
    public function findOneByToken(string $token): ?Session
    {
        return $this->createQueryBuilder('s')
            ->addSelect('u')->join('s.user', 'u')
            ->where('s.token = :token')->setParameter('token', $token)
            ->getQuery()->getOneOrNullResult();
    }
}
