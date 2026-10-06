<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Search;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Search> */
final class SearchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Search::class);
    }

    /**
     * `user.searches.ordered` (`order(updated_at: :desc)`).
     *
     * @return list<Search>
     */
    public function findOrderedForUser(User $user, ?int $limit = null): array
    {
        return $this->findBy(['user' => $user], ['updatedAt' => 'DESC'], $limit);
    }

    public function findOneForUserByQuery(User $user, string $query): ?Search
    {
        return $this->findOneBy(['user' => $user, 'query' => $query]);
    }
}
