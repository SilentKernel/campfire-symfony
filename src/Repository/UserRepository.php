<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\Enum\UserStatus;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<User> */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /** The `active` scope (`enum :status`, default `active`). */
    public function active(string $alias = 'u'): QueryBuilder
    {
        return $this->createQueryBuilder($alias)
            ->andWhere($alias.'.status = :active_status')->setParameter('active_status', UserStatus::Active->value);
    }

    public function findActive(int $id): ?User
    {
        return $this->active()->andWhere('u.id = :id')->setParameter('id', $id)->getQuery()->getOneOrNullResult();
    }

    /**
     * `User.find_by(email_address:)`: an exact, case-sensitive match (SQLite `=` on TEXT); the
     * Rails app neither normalizes nor downcases addresses.
     */
    public function findOneByEmailAddress(string $emailAddress): ?User
    {
        return $this->findOneBy(['emailAddress' => $emailAddress]);
    }

    /** `User.active.find_by(email_address:)`, what `authenticate_by` looks up. */
    public function findActiveByEmailAddress(string $emailAddress): ?User
    {
        return $this->active()->andWhere('u.emailAddress = :email')->setParameter('email', $emailAddress)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * `User.authenticate_bot(bot_key)`: `bot_key.split("-")`, then
     * `active_bots.find_by(id:, bot_token:)`. Ruby's split drops trailing empty fields and
     * find_by casts the id like Integer columns do ("12abc" → 12).
     */
    public function authenticateBot(string $botKey): ?User
    {
        $parts = explode('-', $botKey);
        while ([] !== $parts && '' === end($parts)) {
            array_pop($parts);
        }
        if (\count($parts) < 2 || 1 !== preg_match('/\A\s*[+-]?\d+/', $parts[0], $id)) {
            return null;
        }

        return $this->active()
            ->andWhere('u.role = :bot')->setParameter('bot', UserRole::Bot->value)
            ->andWhere('u.id = :id')->setParameter('id', (int) $id[0])
            ->andWhere('u.botToken = :token')->setParameter('token', $parts[1])
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * `User.active.ordered` (LOWER(name)); `filtered_by` adds `name LIKE %query%`.
     *
     * @return list<User>
     */
    public function findActiveOrdered(?string $filter = null, bool $withoutBots = false): array
    {
        $qb = $this->active()->orderBy('LOWER(u.name)', 'ASC');
        if (null !== $filter) {
            $qb->andWhere('u.name LIKE :filter')->setParameter('filter', '%'.$filter.'%');
        }
        if ($withoutBots) {
            $qb->andWhere('u.role != :bot')->setParameter('bot', UserRole::Bot->value);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * `User.active_bots.ordered`.
     *
     * @return list<User>
     */
    public function findActiveBotsOrdered(): array
    {
        return $this->active()
            ->andWhere('u.role = :bot')->setParameter('bot', UserRole::Bot->value)
            ->orderBy('LOWER(u.name)', 'ASC')
            ->getQuery()->getResult();
    }
}
