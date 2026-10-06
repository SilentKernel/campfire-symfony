<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rooms;

use App\Cable\Broadcaster;
use App\Cable\RecordingBroadcaster;
use App\Domain\Rooms\Memberships;
use App\Domain\Rooms\Rooms;
use App\Domain\Rooms\Sidebar;
use App\Domain\Rooms\UserRooms;
use App\Entity\Room;
use App\Entity\Rooms\Closed;
use App\Entity\Rooms\Direct;
use App\Entity\Rooms\Open;
use App\Entity\User;
use App\Tests\Support\CampfireTestCase;

/** App\Domain\Rooms against the seed (reference/app/models/room.rb, membership.rb, rooms/*.rb). */
final class RoomsDomainTest extends CampfireTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('CAMPFIRE_FROZEN_TIME', '2026-03-02T16:00:00Z');
        self::bootKernel();
    }

    public function testCreateForGrantsTheUsers(): void
    {
        $room = $this->rooms()->createFor(Closed::class, 'Secret', [$this->user('users.kevin'), self::id('users.jz')], $this->user('users.kevin'));

        self::assertInstanceOf(Closed::class, $room);
        self::assertSame([self::id('users.kevin'), self::id('users.jz')], $this->memberIds($room));
        self::assertSame(['mentions'], $this->connection()->fetchFirstColumn('SELECT DISTINCT involvement FROM memberships WHERE room_id = ?', [$room->getId()]));
    }

    public function testOpenRoomsGrantEveryActiveUserAfterCommit(): void
    {
        $room = $this->rooms()->createFor(Open::class, 'Lobby', [self::id('users.kevin')], $this->user('users.kevin'));
        $active = array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT id FROM users WHERE status = 0 ORDER BY id'));
        $members = $this->memberIds($room);
        sort($members);
        self::assertSame($active, $members);
    }

    public function testDirectRoomsAreFoundBySetOfMembers(): void
    {
        $existing = $this->rooms()->findOrCreateDirectFor([self::id('users.jason'), self::id('users.david')], $this->user('users.david'));
        self::assertSame(self::id('rooms.david_and_jason'), $existing->getId());

        $group = $this->rooms()->findOrCreateDirectFor([self::id('users.jz'), self::id('users.kevin'), self::id('users.jason'), self::id('users.david')], $this->user('users.jz'));
        self::assertSame(self::id('rooms.group_direct'), $group->getId());

        $new = $this->rooms()->findOrCreateDirectFor([self::id('users.jz'), self::id('users.david')], $this->user('users.jz'));
        self::assertInstanceOf(Direct::class, $new);
        self::assertNull($new->getName());
        self::assertSame(['everything'], $this->connection()->fetchFirstColumn('SELECT DISTINCT involvement FROM memberships WHERE room_id = ?', [$new->getId()]));
        self::assertSame($new->getId(), $this->rooms()->findOrCreateDirectFor([self::id('users.david'), self::id('users.jz')], $this->user('users.david'))->getId());
    }

    public function testUpdateConvertsAndReloads(): void
    {
        $hq = $this->em()->find(Room::class, self::id('rooms.hq'));
        \assert($hq instanceof Open);
        $closed = $this->rooms()->update($hq, Closed::class, ['name' => 'HQ']);

        self::assertInstanceOf(Closed::class, $closed);
        self::assertSame('Rooms::Closed', $this->connection()->fetchOne('SELECT type FROM rooms WHERE id = ?', [$hq->getId()]));
        self::assertSame('2026-03-02 16:00:00', $this->connection()->fetchOne('SELECT updated_at FROM rooms WHERE id = ?', [$hq->getId()]));

        $open = $this->rooms()->update($closed, Open::class);
        self::assertInstanceOf(Open::class, $open);
        self::assertSame((int) $this->connection()->fetchOne('SELECT COUNT(*) FROM users WHERE status = 0') + 1, \count($this->memberIds($open)));
    }

    public function testDirectRoomsKeepTheirType(): void
    {
        $direct = $this->em()->find(Room::class, self::id('rooms.david_and_jason'));
        \assert($direct instanceof Room);
        $this->expectException(\DomainException::class);
        $this->rooms()->update($direct, Open::class);
    }

    public function testReviseGrantsAndRevokes(): void
    {
        $designers = $this->em()->find(Room::class, self::id('rooms.designers'));
        \assert($designers instanceof Room);
        $broadcaster = $this->broadcaster();
        $broadcaster->clear();

        $this->memberships()->revise($designers, granted: [self::id('users.loner'), self::id('users.jz')], revoked: [self::id('users.kevin')]);

        $members = $this->memberIds($designers);
        self::assertContains(self::id('users.loner'), $members);
        self::assertNotContains(self::id('users.kevin'), $members);
        self::assertSame([['userId' => self::id('users.kevin'), 'reconnect' => true]], $broadcaster->disconnects);
    }

    public function testUnreadAndConnections(): void
    {
        $designers = self::id('rooms.designers');
        $this->connection()->executeStatement("UPDATE memberships SET connected_at = '2026-03-02 15:59:30', unread_at = NULL WHERE room_id = ? AND user_id = ?", [$designers, self::id('users.jz')]);

        $this->memberships()->markUnread($designers, self::id('users.david'), new \DateTimeImmutable('2026-03-02 15:58:00 UTC'));
        $unread = fn (string $user): mixed => $this->connection()->fetchOne('SELECT unread_at FROM memberships WHERE room_id = ? AND user_id = ?', [$designers, self::id($user)]);
        self::assertNull($unread('users.jz'), 'connected');
        self::assertNull($unread('users.david'), 'the creator');
        self::assertSame('2026-03-02 15:58:00', $unread('users.jason'));

        self::assertSame(1, $this->memberships()->disconnectAll());
        self::assertNull($this->connection()->fetchOne('SELECT connected_at FROM memberships WHERE room_id = ? AND user_id = ?', [$designers, self::id('users.jz')]));

        $this->memberships()->connect(self::id('memberships.jason_designers'), 2);
        self::assertSame([2, '2026-03-02 16:00:00', null], array_values((array) $this->connection()->fetchAssociative('SELECT connections, connected_at, unread_at FROM memberships WHERE id = ?', [self::id('memberships.jason_designers')])));
    }

    public function testUserRoomsScopes(): void
    {
        $userRooms = static::getContainer()->get(UserRooms::class);
        \assert($userRooms instanceof UserRooms);
        $david = $this->user('users.david');

        self::assertSame(self::id('rooms.hq'), $userRooms->find($david, (string) self::id('rooms.hq'))?->getId());
        self::assertSame(self::id('rooms.hq'), $userRooms->find($david, ' '.self::id('rooms.hq').'abc')?->getId());
        self::assertNull($userRooms->find($david, (string) self::id('rooms.hq'), UserRooms::DIRECTS));
        self::assertNull($userRooms->find($david, (string) self::id('rooms.david_and_jason'), UserRooms::WITHOUT_DIRECTS));
        self::assertNull($userRooms->find($this->user('users.jz'), (string) self::id('rooms.watercooler')));
        self::assertSame(self::id('rooms.pets'), $userRooms->original($david)?->getId());
        self::assertSame(self::id('rooms.hq'), $userRooms->original($this->user('users.kevin'))?->getId());
        self::assertSame(self::id('rooms.group_direct'), $userRooms->last($david)?->getId());
        self::assertSame(self::id('memberships.david_hq'), $userRooms->membership($david, (string) self::id('rooms.hq'))?->getId());
    }

    public function testDestroyLeavesOtherRoomsAlone(): void
    {
        $hq = $this->em()->find(Room::class, self::id('rooms.hq'));
        \assert($hq instanceof Room);
        $messages = (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM messages WHERE room_id != ?', [$hq->getId()]);
        $this->rooms()->destroy($hq);

        self::assertSame($messages, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM messages'));
        self::assertNull($this->em()->getUnitOfWork()->tryGetById(self::id('rooms.hq'), Room::class) ?: null);
    }

    public function testSidebarQueries(): void
    {
        $sidebar = static::getContainer()->get(Sidebar::class);
        \assert($sidebar instanceof Sidebar);
        [$directs, $shared] = $sidebar->memberships($this->user('users.kevin'));

        self::assertSame([self::id('rooms.david_and_kevin'), self::id('rooms.group_direct'), self::id('rooms.bender_and_kevin')], array_map(static fn ($r): int => $r->id, $directs));
        self::assertSame(['Archive', 'Designers', 'HQ', 'Quiet Corner'], array_map(static fn ($r): ?string => $r->name, $shared));
        self::assertSame([false, true, false, false], array_map(static fn ($r): bool => $r->unread, $shared));

        $members = $sidebar->directMembers([self::id('rooms.group_direct'), self::id('rooms.bender_and_kevin')], $this->user('users.kevin'));
        self::assertSame(['David', 'Jason', 'JZ'], array_map(static fn (User $u): string => $u->getName(), $members[self::id('rooms.group_direct')]));
        self::assertSame(['Bender Bot'], array_map(static fn (User $u): string => $u->getName(), $members[self::id('rooms.bender_and_kevin')]));
    }

    private function rooms(): Rooms
    {
        $rooms = static::getContainer()->get(Rooms::class);
        \assert($rooms instanceof Rooms);

        return $rooms;
    }

    private function memberships(): Memberships
    {
        $memberships = static::getContainer()->get(Memberships::class);
        \assert($memberships instanceof Memberships);

        return $memberships;
    }

    private function broadcaster(): RecordingBroadcaster
    {
        $broadcaster = static::getContainer()->get(Broadcaster::class);
        \assert($broadcaster instanceof RecordingBroadcaster);

        return $broadcaster;
    }

    private function user(string $label): User
    {
        $user = $this->em()->find(User::class, self::id($label));
        \assert($user instanceof User);

        return $user;
    }

    /** @return list<int> */
    private function memberIds(Room $room): array
    {
        return array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT user_id FROM memberships WHERE room_id = ? ORDER BY id', [$room->getId()]));
    }
}
