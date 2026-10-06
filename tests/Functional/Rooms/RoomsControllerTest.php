<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rooms;

use App\Cable\StreamNames;

/** RoomsController (reference/app/controllers/rooms_controller.rb). */
final class RoomsControllerTest extends RoomsTestCase
{
    public function testShowRendersTheRoomAndRemembersIt(): void
    {
        $this->signIn('users.david');
        $hq = self::id('rooms.hq');
        $response = $this->get('/rooms/'.$hq);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('Accept', $response->headers->get('Vary'));
        self::assertStringStartsWith('last_room='.$hq.'; path=/; expires=', (string) self::setCookie($response, 'last_room'));
        self::assertStringEndsWith('; samesite=lax', (string) self::setCookie($response, 'last_room'));
        self::assertStringNotContainsString('httponly', (string) self::setCookie($response, 'last_room'));

        $page = $this->crawler($response);
        self::assertSame('HQ', $page->filter('title')->text());
        self::assertSame((string) $hq, $page->filter('meta[name="current-room-id"]')->attr('content'));
        self::assertCount(1, $page->filter('#messages_rooms_open_'.$hq));
        self::assertSame('/users/me/sidebar', $page->filter('turbo-frame#user_sidebar')->attr('src'));
        self::assertSame('/rooms/opens/'.$hq.'/edit', $page->filter('#nav a.btn')->attr('href'));
        self::assertSame('RoomMessagesChannel', $page->filter('turbo-cable-stream-source')->attr('channel'));
        self::assertCount(1, $page->filter('form#composer[action="/rooms/'.$hq.'/messages"]'));
    }

    public function testDirectRoomsAreNamedAfterTheOtherMembers(): void
    {
        $this->signIn('users.david');
        $page = $this->crawler($this->get('/rooms/'.self::id('rooms.group_direct')));
        self::assertSame('Jason, Kevin, and JZ', $page->filter('title')->text());
        self::assertSame('Ping with Jason, Kevin, and JZ', trim((string) preg_replace('/\s+/', ' ', $page->filter('h1.room__contents')->text())));

        $this->signIn('users.kevin');
        self::assertSame('David', $this->crawler($this->get('/rooms/'.self::id('rooms.david_and_kevin')))->filter('title')->text());
    }

    public function testTheOriginalRoomInvitesPeopleUntilItIsPaged(): void
    {
        $this->signIn('users.david');
        self::assertCount(1, $this->crawler($this->get('/rooms/'.self::id('rooms.pets')))->filter('#system_welcome'));
        self::assertCount(0, $this->crawler($this->get('/rooms/'.self::id('rooms.hq')))->filter('#system_welcome'));

        $this->connection()->executeStatement(
            "WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 41) INSERT INTO messages (room_id, creator_id, client_message_id, created_at, updated_at) SELECT ?, ?, 'paged-' || i, '2026-03-01 10:00:00', '2026-03-01 10:00:00' FROM n",
            [self::id('rooms.pets'), self::id('users.david')],
        );
        self::assertCount(0, $this->crawler($this->get('/rooms/'.self::id('rooms.pets')))->filter('#system_welcome'));
    }

    public function testShowAroundAMessage(): void
    {
        $this->signIn('users.david');
        $room = self::id('rooms.watercooler');
        $response = $this->get('/rooms/'.$room.'/@'.self::id('messages.busy_001'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('last_room='.$room.';', (string) self::setCookie($response, 'last_room'));
        $ids = $this->crawler($response)->filter('.messages > .message')->each(static fn ($node): string => (string) $node->attr('data-message-id'));
        if ([] !== $ids) {
            self::assertContains((string) self::id('messages.busy_001'), $ids);
        }
    }

    public function testInaccessibleRoomsRedirectToTheRootWithAnAlert(): void
    {
        $this->signIn('users.jz');
        foreach (['/rooms/'.self::id('rooms.watercooler'), '/rooms/999', '/rooms/abc'] as $path) {
            $response = $this->get($path);
            self::assertRedirectsTo('http://localhost/', $response);
            self::assertNull(self::setCookie($response, 'last_room'));
            self::assertSame(['discard' => [], 'flashes' => ['alert' => 'Room not found or inaccessible']], $this->sessionData()['flash'] ?? null);
        }
    }

    public function testSignedOutVisitorsAreSentToSignIn(): void
    {
        $response = $this->get('/rooms/'.self::id('rooms.hq'));
        self::assertRedirectsTo('http://localhost/session/new', $response);
    }

    public function testOtherFormatsAreNotAcceptable(): void
    {
        $this->signIn('users.david');
        self::assertSame(406, $this->get('/rooms/'.self::id('rooms.hq').'.json')->getStatusCode());
        self::assertSame(406, $this->get('/rooms/'.self::id('rooms.hq'), ['HTTP_ACCEPT' => 'application/json'])->getStatusCode());
    }

    public function testIndexRedirectsToTheLastRoom(): void
    {
        $this->signIn('users.david');
        self::assertRedirectsTo('http://localhost/rooms/'.self::id('rooms.group_direct'), $this->get('/rooms'));
        // GET /rooms/opens etc. are rooms#show with id "opens" in Rails' route order.
        foreach (['opens', 'closeds', 'directs'] as $type) {
            self::assertRedirectsTo('http://localhost/', $this->get('/rooms/'.$type));
        }
    }

    public function testIndexWithoutRoomsFails(): void
    {
        $this->signIn('users.loner');
        self::assertSame(500, $this->get('/rooms')->getStatusCode());
    }

    public function testAdministratorsDestroyRooms(): void
    {
        $this->signIn('users.david');
        $hq = self::id('rooms.hq');
        $this->broadcaster()->clear();
        $response = $this->submit('DELETE', '/rooms/'.$hq);

        self::assertRedirectsTo('http://localhost/', $response);
        self::assertFalse($this->fetchValue('SELECT 1 FROM rooms WHERE id = ?', $hq));
        self::assertSame(0, (int) $this->fetchValue('SELECT COUNT(*) FROM memberships WHERE room_id = ?', $hq));
        self::assertSame(0, (int) $this->fetchValue('SELECT COUNT(*) FROM messages WHERE room_id = ?', $hq));
        self::assertSame([['stream' => StreamNames::rooms(), 'payload' => '<turbo-stream action="remove" target="list_rooms_open_'.$hq.'"></turbo-stream>']], $this->broadcaster()->broadcasts);
        self::assertSame([], $this->broadcaster()->disconnects);
    }

    public function testDestroyingARoomDestroysItsMessages(): void
    {
        $this->signIn('users.david');
        $room = self::id('rooms.watercooler');
        $messageIds = $this->connection()->fetchFirstColumn('SELECT id FROM messages WHERE room_id = ?', [$room]);
        self::assertNotEmpty($messageIds);

        self::assertRedirectsTo('http://localhost/', $this->submit('DELETE', '/rooms/'.$room));
        $in = implode(',', array_map(intval(...), $messageIds));
        self::assertSame(0, (int) $this->fetchValue("SELECT COUNT(*) FROM boosts WHERE message_id IN ($in)"));
        self::assertSame(0, (int) $this->fetchValue("SELECT COUNT(*) FROM action_text_rich_texts WHERE record_type = 'Message' AND record_id IN ($in)"));
        self::assertSame(0, (int) $this->fetchValue("SELECT COUNT(*) FROM message_search_index WHERE rowid IN ($in)"));
        self::assertSame(0, (int) $this->fetchValue("SELECT COUNT(*) FROM active_storage_attachments WHERE record_type = 'Message' AND record_id IN ($in)"));
    }

    public function testMembersWhoCannotAdministerTheRoomAreForbidden(): void
    {
        $this->signIn('users.kevin');
        $response = $this->submit('DELETE', '/rooms/'.self::id('rooms.hq'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertSame(1, (int) $this->fetchValue('SELECT COUNT(*) FROM rooms WHERE id = ?', self::id('rooms.hq')));
    }

    public function testCreatorsDestroyTheirRooms(): void
    {
        $this->signIn('users.kevin');
        self::assertRedirectsTo('http://localhost/', $this->submit('DELETE', '/rooms/'.self::id('rooms.quiet')));
        self::assertFalse($this->fetchValue('SELECT 1 FROM rooms WHERE id = ?', self::id('rooms.quiet')));
    }

    public function testDestroyNeedsTheAuthenticityToken(): void
    {
        $this->signIn('users.david');
        self::assertSame(422, $this->submitWithoutToken('DELETE', '/rooms/'.self::id('rooms.hq'))->getStatusCode());
        self::assertSame(1, (int) $this->fetchValue('SELECT COUNT(*) FROM rooms WHERE id = ?', self::id('rooms.hq')));
    }

    public function testRoutesRailsDoesNotHave(): void
    {
        $this->signIn('users.david');
        self::assertSame(404, $this->get('/rooms/new')->getStatusCode());
        self::assertSame(404, $this->get('/rooms/'.self::id('rooms.hq').'/edit')->getStatusCode());
        self::assertSame(404, $this->submit('PATCH', '/rooms/'.self::id('rooms.hq'))->getStatusCode());
        self::assertSame(404, $this->submit('POST', '/rooms')->getStatusCode());
    }
}
