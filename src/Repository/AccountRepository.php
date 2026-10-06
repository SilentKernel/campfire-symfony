<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Account> */
final class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /** `Account.first`: the installation's single account, null before first run. */
    public function findSingleton(): ?Account
    {
        return $this->createQueryBuilder('a')->orderBy('a.id')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }
}
