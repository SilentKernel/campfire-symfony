<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rooms;

use App\Cable\StreamNames;

/** Rooms::OpensController (reference/app/controllers/rooms/opens_controller.rb). */
final class OpensControllerTest extends RoomsTestCase
{
    public function testNew(): void
    {
        $this->signIn('users.kevin');
        $response = $this->get('/rooms/opens/new');

        self::assertSame(200, $response->getStatusCode());
        $page = $this->crawler($response);
        self::assertSame('New chat room', $page->filter('title')->text());
        self::assertSame('New room', $page->filter('input#room_name')->attr('value'));
        self::assertSame('/rooms/opens', $page->filter('main form')->attr('action'));
        self::assertSame('/rooms/closeds/new', $page->filter('a.btn--faux')->attr('href'));
        // User.active.ordered (bots included, deactivated and banned users left out)
        self::assertSame(['bender bot', 'david', 'deploy bot', 'jason', 'jz', 'kevin', 'lonely lou'], $page->filter('li[data-value]')->each(static fn ($li): string => (string) $li->attr('data-value')));
    }

    public function testRoomCreationCanBeRestrictedToAdministrators(): void
    {
        $this->connection()->executeStatement('UPDATE accounts SET settings = ?', ['{"restrict_room_creation_to_administrators":true}']);

        $this->signIn('users.kevin');
        self::assertSame(403, $this->get('/rooms/opens/new')->getStatusCode());
        self::assertSame(403, $this->submit('POST', '/rooms/opens', ['room' => ['name' => 'Nope']])->getStatusCode());
        self::assertFalse($this->fetchValue("SELECT 1 FROM rooms WHERE name = 'Nope'"));

        $this->signIn('users.david');
        self::assertSame(200, $this->get('/rooms/opens/new')->getStatusCode());
    }

    public function testCreateGrantsEveryActiveUserAndBroadcasts(): void
    {
        $this->signIn('users.kevin');
        $this->broadcaster()->clear();
        $response = $this->submit('POST', '/rooms/opens', ['room' => ['name' => 'Lounge']]);

        $room = $this->connection()->fetchAssociative("SELECT * FROM rooms WHERE name = 'Lounge'");
        self::assertIsArray($room);
        self::assertRedirectsTo('http://localhost/rooms/'.$room['id'], $response);
        self::assertSame('Rooms::Open', $room['type']);
        self::assertSame(self::id('users.kevin'), (int) $room['creator_id']);
        self::assertSame('2026-03-02 16:00:00', $room['created_at']);

        $members = array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT user_id FROM memberships WHERE room_id = ? ORDER BY user_id', [$room['id']]));
        $active = array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT id FROM users WHERE status = 0 ORDER BY id'));
        self::assertSame($active, $members);
        self::assertSame(['mentions'], $this->connection()->fetchFirstColumn('SELECT DISTINCT involvement FROM memberships WHERE room_id = ?', [$room['id']]));

        self::assertCount(1, $this->broadcaster()->broadcasts);
        self::assertSame(StreamNames::rooms(), $this->broadcaster()->broadcasts[0]['stream']);
        self::assertSame(
            '<turbo-stream action="prepend" target="shared_rooms"><template><a id="list_rooms_open_'.$room['id'].'" data-rooms-list-target="room" data-room-id="'.$room['id'].'" data-badge-dot-target="unread" data-sorted-list-target="item" data-sorted-list-name="Lounge" style="--column-gap: 0.5em" class="align-center gap room btn txt-nowrap" href="/rooms/'.$room['id'].'">'."\n".'  <span class="overflow-ellipsis">Lounge</span>'."\n".'</a>'."\n".'</template></turbo-stream>',
            $this->broadcaster()->broadcasts[0]['payload'],
        );
    }

    public function testCreateRequiresTheRoomParams(): void
    {
        $this->signIn('users.kevin');
        self::assertSame(400, $this->submit('POST', '/rooms/opens')->getStatusCode());
    }

    public function testCreateNeedsTheAuthenticityToken(): void
    {
        $this->signIn('users.kevin');
        $this->client->request('POST', '/rooms/opens', ['room' => ['name' => 'Lounge']]);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testEditAClosedRoomAsOpen(): void
    {
        $this->signIn('users.david');
        $designers = self::id('rooms.designers');
        $page = $this->crawler($this->get('/rooms/opens/'.$designers.'/edit'));

        self::assertSame('Edit settings for Designers', $page->filter('title')->text());
        self::assertSame('/rooms/opens/'.$designers, $page->filter('main form')->first()->attr('action'));
        self::assertSame('patch', $page->filter('main form input[name="_method"]')->attr('value'));
        self::assertSame('/rooms/closeds/'.$designers.'/edit', $page->filter('a.btn--faux')->attr('href'));
        self::assertSame('http://localhost/rooms/'.$designers, $page->filter('form.button_to')->attr('action'));
    }

    public function testMembersWhoCannotAdministerSeeTheFormReadOnly(): void
    {
        $this->signIn('users.jason');
        $this->connection()->executeStatement('UPDATE users SET role = 0 WHERE id = ?', [self::id('users.jason')]);
        $page = $this->crawler($this->get('/rooms/opens/'.self::id('rooms.hq').'/edit'));

        self::assertCount(0, $page->filter('input#room_name'));
        self::assertSame('HQ', trim($page->filter('h1.txt-x-large')->text()));
        self::assertCount(0, $page->filter('a.btn--faux'));
        self::assertCount(0, $page->filter('form.button_to'));
        self::assertCount(0, $page->filter('button[type="submit"].txt-large'));
    }

    public function testUpdateRenamesAndConvertsAClosedRoom(): void
    {
        $this->signIn('users.david');
        $designers = self::id('rooms.designers');
        $this->broadcaster()->clear();
        $response = $this->submit('PATCH', '/rooms/opens/'.$designers, ['room' => ['name' => 'Everyone designs']]);

        self::assertRedirectsTo('http://localhost/rooms/'.$designers, $response);
        $room = $this->connection()->fetchAssociative('SELECT * FROM rooms WHERE id = ?', [$designers]);
        self::assertSame(['Everyone designs', 'Rooms::Open', '2026-03-02 16:00:00'], [$room['name'] ?? null, $room['type'] ?? null, $room['updated_at'] ?? null]);
        // Becoming open grants every active user (after commit)
        self::assertSame(
            (int) $this->fetchValue('SELECT COUNT(*) FROM users WHERE status = 0') + 1, // + Mallory (banned), already a member
            (int) $this->fetchValue('SELECT COUNT(*) FROM memberships WHERE room_id = ?', $designers),
        );
        self::assertSame(StreamNames::rooms(), $this->broadcaster()->broadcasts[0]['stream']);
        self::assertStringStartsWith('<turbo-stream action="replace" target="list_rooms_open_'.$designers.'"><template><a id="list_rooms_open_'.$designers.'"', (string) $this->broadcaster()->broadcasts[0]['payload']);
    }

    public function testUpdateWithoutChangesTouchesNothing(): void
    {
        $this->signIn('users.david');
        $hq = self::id('rooms.hq');
        self::assertRedirectsTo('http://localhost/rooms/'.$hq, $this->submit('PATCH', '/rooms/opens/'.$hq, ['room' => ['name' => 'HQ']]));
        self::assertSame('2026-01-03 17:00:00', $this->fetchValue('SELECT updated_at FROM rooms WHERE id = ?', $hq));
    }

    public function testUpdateIsForAdministratorsAndCreators(): void
    {
        $this->signIn('users.kevin');
        self::assertSame(403, $this->submit('PATCH', '/rooms/opens/'.self::id('rooms.hq'), ['room' => ['name' => 'Mine']])->getStatusCode());
        self::assertSame('HQ', $this->fetchValue('SELECT name FROM rooms WHERE id = ?', self::id('rooms.hq')));
    }

    public function testDirectRoomsAreOutOfReach(): void
    {
        $this->signIn('users.david');
        $direct = self::id('rooms.david_and_jason');
        self::assertRedirectsTo('http://localhost/', $this->get('/rooms/opens/'.$direct.'/edit'));
        self::assertRedirectsTo('http://localhost/', $this->submit('PATCH', '/rooms/opens/'.$direct, ['room' => ['name' => 'Public now']]));
        self::assertSame('Rooms::Direct', $this->fetchValue('SELECT type FROM rooms WHERE id = ?', $direct));
    }

    public function testShowRedirectsToTheRoom(): void
    {
        $this->signIn('users.david');
        $response = $this->get('/rooms/opens/'.self::id('rooms.hq'));
        self::assertRedirectsTo('http://localhost/rooms/'.self::id('rooms.hq'), $response);
        self::assertStringStartsWith('last_room='.self::id('rooms.hq').';', (string) self::setCookie($response, 'last_room'));
    }

    public function testDestroyIsInheritedWithoutSetRoom(): void
    {
        $this->signIn('users.david');
        self::assertSame(500, $this->submit('DELETE', '/rooms/opens/'.self::id('rooms.hq'))->getStatusCode());
        self::assertSame(1, (int) $this->fetchValue('SELECT COUNT(*) FROM rooms WHERE id = ?', self::id('rooms.hq')));
    }
}
