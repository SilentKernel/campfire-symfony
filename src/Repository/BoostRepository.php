<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Boost;
use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Boost> */
final class BoostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Boost::class);
    }

    /**
     * `message.boosts.ordered`.
     *
     * @return list<Boost>
     */
    public function findOrderedForMessage(Message $message): array
    {
        return $this->findBy(['message' => $message], ['createdAt' => 'ASC']);
    }

    /**
     * The boosts of several messages, ordered (the `with_boosts` preload), keyed by message id.
     *
     * @param list<int> $messageIds
     *
     * @return array<int, list<Boost>>
     */
    public function findOrderedByMessageIds(array $messageIds): array
    {
        if ([] === $messageIds) {
            return [];
        }
        $boosts = $this->createQueryBuilder('b')
            ->addSelect('u')->join('b.booster', 'u')
            ->where('IDENTITY(b.message) IN (:ids)')->setParameter('ids', $messageIds)
            ->orderBy('b.createdAt', 'ASC')->addOrderBy('b.id', 'ASC')
            ->getQuery()->getResult();
        $byMessage = array_fill_keys($messageIds, []);
        foreach ($boosts as $boost) {
            $byMessage[$boost->getMessage()->getId()][] = $boost;
        }

        return $byMessage;
    }
}
