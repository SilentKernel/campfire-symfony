<?php

declare(strict_types=1);

namespace App\Tests\Functional\Database;

use App\Entity\Account;
use App\Entity\Ban;
use App\Entity\Membership;
use App\Entity\Room;
use App\Entity\Session;
use App\Entity\User;
use App\Repository\AccountRepository;
use App\Repository\BanRepository;
use App\Repository\MembershipRepository;
use App\Repository\RoomRepository;
use App\Repository\SessionRepository;
use App\Repository\UserRepository;
use App\Tests\Support\CampfireTestCase;

final class RepositoryTest extends CampfireTestCase
{
    public function testSessionByToken(): void
    {
        $token = (string) $this->connection()->fetchOne('SELECT token FROM sessions WHERE id = ?', [self::id('sessions.david_safari')]);
        $repository = $this->repository(Session::class, SessionRepository::class);

        self::assertSame(self::id('users.david'), $repository->findOneByToken($token)?->getUser()->getId());
        self::assertNull($repository->findOneByToken(strtolower($token)));
    }

    public function testUserFinders(): void
    {
        $users = $this->repository(User::class, UserRepository::class);

        self::assertSame(self::id('users.david'), $users->findActiveByEmailAddress((string) self::labels('emails.david'))?->getId());
        self::assertNull($users->findActiveByEmailAddress(strtoupper((string) self::labels('emails.david'))), 'case-sensitive like Rails');
        self::assertNull($users->findActiveByEmailAddress((string) self::labels('emails.mallory')), 'banned');
        self::assertNull($users->findActive(self::id('users.rita')));
        self::assertNotNull($users->findOneByEmailAddress((string) self::labels('emails.rita')));

        self::assertSame(self::id('users.bender'), $users->authenticateBot((string) self::labels('bot_keys.bender'))?->getId());
        self::assertNull($users->authenticateBot(self::id('users.bender').'-wrong'));
        self::assertNull($users->authenticateBot('garbage'));

        $names = array_map(static fn (User $user): string => $user->getName(), $users->findActiveOrdered());
        $sorted = $names;
        usort($sorted, static fn (string $a, string $b): int => strtolower($a) <=> strtolower($b));
        self::assertSame($sorted, $names);
        self::assertNotContains('Rita Lopez', $names);
    }

    public function testRoomFinders(): void
    {
        $rooms = $this->repository(Room::class, RoomRepository::class);

        self::assertSame(self::id('rooms.pets'), $rooms->findOriginal()?->getId());
        self::assertEqualsCanonicalizing(
            array_map(intval(...), $this->connection()->fetchFirstColumn("SELECT id FROM rooms WHERE type = 'Rooms::Open'")),
            $rooms->findOpenIds(),
        );
        foreach ($rooms->findWithoutDirectsOrdered() as $room) {
            self::assertFalse($room->isDirect());
        }
    }

    public function testMembershipFinders(): void
    {
        $memberships = $this->repository(Membership::class, MembershipRepository::class);

        self::assertSame(
            self::id('memberships.david_watercooler'),
            $memberships->findOneFor(self::id('rooms.watercooler'), self::id('users.david'))?->getId(),
        );

        $david = $this->em()->find(User::class, self::id('users.david'));
        \assert(null !== $david);
        $visible = $memberships->findVisibleWithOrderedRoom($david);
        $expected = $this->connection()->fetchFirstColumn(
            "SELECT m.id FROM memberships m JOIN rooms r ON r.id = m.room_id WHERE m.user_id = ? AND m.involvement != 'invisible' ORDER BY LOWER(r.name)",
            [self::id('users.david')],
        );
        self::assertSame(array_map(intval(...), $expected), array_map(static fn (Membership $m): int => $m->getId(), $visible));
    }

    public function testAccountAndBans(): void
    {
        self::assertSame(self::id('accounts.signal'), $this->repository(Account::class, AccountRepository::class)->findSingleton()?->getId());

        $bans = $this->repository(Ban::class, BanRepository::class);
        self::assertTrue($bans->isBanned((string) self::labels('ips.banned')));
        self::assertFalse($bans->isBanned('198.51.100.1'));
    }

    /**
     * @template T of object
     * @template R of object
     *
     * @param class-string<T> $entity
     * @param class-string<R> $repository
     *
     * @return R
     */
    private function repository(string $entity, string $repository): object
    {
        $instance = $this->em()->getRepository($entity);
        self::assertInstanceOf($repository, $instance);

        return $instance;
    }
}
