<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Rooms\RubyInteger;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\UserStatus;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The account's people (reference/app/controllers/accounts_controller.rb#account_users and
 * accounts/users_controller.rb#index), with geared_pagination's PortionAtOffset at a fixed
 * `per_page: 500`.
 */
final readonly class AccountUsers
{
    public const int PER_PAGE = 500;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * `User.where(status: [:active, :banned])` for administrators, else `User.active`;
     * `.ordered.without_bots`.
     *
     * @param list<UserStatus> $statuses
     *
     * @return list<User>
     */
    public function ordered(array $statuses, ?int $limit = null, int $offset = 0): array
    {
        $qb = $this->em->createQueryBuilder()->select('u')->from(User::class, 'u')
            ->where('u.status IN (:statuses)')->setParameter('statuses', array_map(static fn (UserStatus $s): int => $s->value, $statuses))
            ->andWhere('u.role != :bot')->setParameter('bot', UserRole::Bot->value)
            ->orderBy('LOWER(u.name)', 'ASC');
        if (null !== $limit) {
            $qb->setMaxResults($limit)->setFirstResult($offset);
        }

        return $qb->getQuery()->getResult();
    }

    /** @param list<UserStatus> $statuses */
    public function count(array $statuses): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(u.id)')->from(User::class, 'u')
            ->where('u.status IN (:statuses)')->setParameter('statuses', array_map(static fn (UserStatus $s): int => $s->value, $statuses))
            ->andWhere('u.role != :bot')->setParameter('bot', UserRole::Bot->value)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * `set_page_and_extract_portion_from(users, per_page: 500)`: the page's records and whether it
     * is the last page (`number == page_count`, page_count at least 1).
     *
     * @param list<UserStatus> $statuses
     *
     * @return array{number: int, records: list<User>, last: bool, next: int}
     */
    public function page(array $statuses, mixed $param): array
    {
        $number = self::pageNumber($param);
        $pageCount = max(1, (int) ceil($this->count($statuses) / self::PER_PAGE));

        return [
            'number' => $number,
            'records' => $this->ordered($statuses, self::PER_PAGE, ($number - 1) * self::PER_PAGE),
            'last' => $number === $pageCount,
            'next' => $number + 1,
        ];
    }

    /** `param.to_i > 0 ? param.to_i : 1` */
    public static function pageNumber(mixed $param): int
    {
        if (!\is_string($param)) {
            return 1;
        }
        $number = RubyInteger::toI($param);

        return 1 === preg_match('/\A[1-9]\d{0,8}\z/', $number) ? (int) $number : 1;
    }
}
