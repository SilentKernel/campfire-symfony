<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rooms;

use App\Cable\StreamNames;

/** Rooms::ClosedsController (reference/app/controllers/rooms/closeds_controller.rb). */
final class ClosedsControllerTest extends RoomsTestCase
{
    public function testNewListsEveryoneUnselectedWithTheCreatorFixed(): void
    {
        $this->signIn('users.kevin');
        $page = $this->crawler($this->get('/rooms/closeds/new'));

        self::assertSame('/rooms/closeds', $page->filter('main form')->attr('action'));
        self::assertSame('/rooms/opens/new', $page->filter('a.btn--faux')->attr('href'));
        self::assertSame([(string) self::id('users.kevin')], $page->filter('input[type="hidden"][name="user_ids[]"]')->each(static fn ($i): string => (string) $i->attr('value')));
        self::assertCount(6, $page->filter('input[type="checkbox"][name="user_ids[]"]'));
        self::assertCount(0, $page->filter('input[type="checkbox"][name="user_ids[]"][checked]'));
    }

    public function testCreateGrantsTheSelectedUsersAndBroadcastsToEach(): void
    {
        $this->signIn('users.kevin');
        $this->broadcaster()->clear();
        $users = [self::id('users.kevin'), self::id('users.jz'), 999999];
        $response = $this->submit('POST', '/rooms/closeds', ['room' => ['name' => 'Secret'], 'user_ids' => $users]);

        $id = (int) $this->fetchValue("SELECT id FROM rooms WHERE name = 'Secret' AND type = 'Rooms::Closed'");
        self::assertRedirectsTo('http://localhost/rooms/'.$id, $response);
        $members = array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT user_id FROM memberships WHERE room_id = ? ORDER BY user_id', [$id]));
        $expected = [self::id('users.kevin'), self::id('users.jz')];
        sort($expected);
        self::assertSame($expected, $members);

        $streams = array_column($this->broadcaster()->broadcasts, 'stream');
        sort($streams);
        $expectedStreams = array_map(StreamNames::userRooms(...), $expected);
        sort($expectedStreams);
        self::assertSame($expectedStreams, $streams);
        self::assertStringStartsWith('<turbo-stream action="prepend" target="shared_rooms"><template><a id="list_rooms_closed_'.$id.'"', (string) $this->broadcaster()->broadcasts[0]['payload']);
    }

    public function testEditSplitsSelectedAndUnselectedUsers(): void
    {
        $this->signIn('users.david');
        $page = $this->crawler($this->get('/rooms/closeds/'.self::id('rooms.designers').'/edit'));

        $checked = $page->filter('input[type="checkbox"][name="user_ids[]"][checked]')->each(static fn ($i): int => (int) $i->attr('value'));
        self::assertSame([self::id('users.david'), self::id('users.deploy_bot'), self::id('users.jason'), self::id('users.jz'), self::id('users.kevin')], $checked);
        self::assertCount(2, $page->filter('input[type="checkbox"][name="user_ids[]"]:not([checked])'));
    }

    public function testUpdateRevisesMemberships(): void
    {
        $this->signIn('users.david');
        $designers = self::id('rooms.designers');
        $this->broadcaster()->clear();
        $granted = [self::id('users.david'), self::id('users.jz'), self::id('users.loner')];
        $response = $this->submit('PUT', '/rooms/closeds/'.$designers, ['room' => ['name' => 'Design'], 'user_ids' => $granted]);

        self::assertRedirectsTo('http://localhost/rooms/'.$designers, $response);
        self::assertSame('Design', $this->fetchValue('SELECT name FROM rooms WHERE id = ?', $designers));
        $members = array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT user_id FROM memberships WHERE room_id = ? ORDER BY user_id', [$designers]));
        sort($granted);
        self::assertSame($granted, $members);
        // Existing memberships are kept as they were (insert_all skips conflicts)
        self::assertSame('everything', $this->fetchValue('SELECT involvement FROM memberships WHERE room_id = ? AND user_id = ?', $designers, self::id('users.jz')));
        self::assertSame('mentions', $this->fetchValue('SELECT involvement FROM memberships WHERE room_id = ? AND user_id = ?', $designers, self::id('users.loner')));
        self::assertMatchesRegularExpression('/\A\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d{3}\z/', (string) $this->fetchValue('SELECT created_at FROM memberships WHERE room_id = ? AND user_id = ?', $designers, self::id('users.loner')));

        // The revoked users' connections are reset after commit
        $revoked = array_column($this->broadcaster()->disconnects, 'userId');
        sort($revoked);
        $expectedRevoked = [self::id('users.jason'), self::id('users.kevin'), self::id('users.mallory'), self::id('users.deploy_bot')];
        sort($expectedRevoked);
        self::assertSame($expectedRevoked, $revoked);
        self::assertSame([true], array_values(array_unique(array_column($this->broadcaster()->disconnects, 'reconnect'))));

        // broadcast_update_room: to the remaining members
        $streams = array_column($this->broadcaster()->broadcasts, 'stream');
        sort($streams);
        $expectedStreams = array_map(StreamNames::userRooms(...), $granted);
        sort($expectedStreams);
        self::assertSame($expectedStreams, $streams);
        self::assertStringStartsWith('<turbo-stream action="replace" target="list_rooms_closed_'.$designers.'">', (string) $this->broadcaster()->broadcasts[0]['payload']);
    }

    public function testUpdateConvertsAnOpenRoom(): void
    {
        $this->signIn('users.david');
        $hq = self::id('rooms.hq');
        $this->submit('PATCH', '/rooms/closeds/'.$hq, ['room' => ['name' => 'HQ'], 'user_ids' => [self::id('users.david')]]);

        self::assertSame('Rooms::Closed', $this->fetchValue('SELECT type FROM rooms WHERE id = ?', $hq));
        self::assertSame([self::id('users.david')], array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT user_id FROM memberships WHERE room_id = ?', [$hq])));
        self::assertSame('2026-03-02 16:00:00', $this->fetchValue('SELECT updated_at FROM rooms WHERE id = ?', $hq));
    }

    public function testUpdateWithoutUsersRevokesEveryone(): void
    {
        $this->signIn('users.david');
        $designers = self::id('rooms.designers');
        $this->submit('PATCH', '/rooms/closeds/'.$designers, ['room' => ['name' => 'Designers']]);
        self::assertSame(0, (int) $this->fetchValue('SELECT COUNT(*) FROM memberships WHERE room_id = ?', $designers));
    }

    public function testUpdateIsForAdministratorsAndCreators(): void
    {
        $this->signIn('users.jz');
        self::assertSame(403, $this->submit('PATCH', '/rooms/closeds/'.self::id('rooms.designers'), ['room' => ['name' => 'JZ'], 'user_ids' => [self::id('users.jz')]])->getStatusCode());
        self::assertSame(6, (int) $this->fetchValue('SELECT COUNT(*) FROM memberships WHERE room_id = ?', self::id('rooms.designers')));
    }

    public function testDirectRoomsAreOutOfReach(): void
    {
        $this->signIn('users.david');
        self::assertRedirectsTo('http://localhost/', $this->submit('PATCH', '/rooms/closeds/'.self::id('rooms.group_direct'), ['room' => ['name' => 'x'], 'user_ids' => [self::id('users.david')]]));
        self::assertSame(4, (int) $this->fetchValue('SELECT COUNT(*) FROM memberships WHERE room_id = ?', self::id('rooms.group_direct')));
    }

    public function testDestroyIsInheritedWithoutSetRoom(): void
    {
        $this->signIn('users.david');
        self::assertSame(500, $this->submit('DELETE', '/rooms/closeds/'.self::id('rooms.designers'))->getStatusCode());
    }
}
