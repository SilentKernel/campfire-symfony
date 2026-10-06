<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rooms;

use App\Cable\StreamNames;

/** Rooms::DirectsController (reference/app/controllers/rooms/directs_controller.rb). */
final class DirectsControllerTest extends RoomsTestCase
{
    public function testCreateFindsTheExistingDirectRoom(): void
    {
        $this->signIn('users.david');
        $this->broadcaster()->clear();
        $rooms = (int) $this->fetchValue('SELECT COUNT(*) FROM rooms');
        $response = $this->submit('POST', '/rooms/directs?user_ids%5B%5D='.self::id('users.jason'));

        self::assertRedirectsTo('http://localhost/rooms/'.self::id('rooms.david_and_jason'), $response);
        self::assertSame($rooms, (int) $this->fetchValue('SELECT COUNT(*) FROM rooms'));
        // Still re-broadcast to both members, each with their own partial
        $streams = array_column($this->broadcaster()->broadcasts, 'stream');
        self::assertSame([StreamNames::userRooms(self::id('users.jason')), StreamNames::userRooms(self::id('users.david'))], $streams);
        self::assertStringStartsWith('<turbo-stream action="prepend" target="direct_rooms"><template>', (string) $this->broadcaster()->broadcasts[0]['payload']);
        self::assertStringContainsString('David', (string) $this->broadcaster()->broadcasts[0]['payload']);
        self::assertStringContainsString('Jason', (string) $this->broadcaster()->broadcasts[1]['payload']);
    }

    public function testCreateMakesANewDirectRoom(): void
    {
        $this->signIn('users.kevin');
        $this->broadcaster()->clear();
        $response = $this->submit('POST', '/rooms/directs', ['user_ids' => [self::id('users.jz'), 'nope']]);

        $id = (int) $this->fetchValue("SELECT MAX(id) FROM rooms WHERE type = 'Rooms::Direct'");
        self::assertRedirectsTo('http://localhost/rooms/'.$id, $response);
        self::assertSame(self::id('users.kevin'), (int) $this->fetchValue('SELECT creator_id FROM rooms WHERE id = ?', $id));
        self::assertNull($this->fetchValue('SELECT name FROM rooms WHERE id = ?', $id));
        self::assertSame(
            [[self::id('users.kevin'), 'everything'], [self::id('users.jz'), 'everything']],
            array_map(static fn (array $r): array => [(int) $r['user_id'], $r['involvement']], $this->connection()->fetchAllAssociative('SELECT user_id, involvement FROM memberships WHERE room_id = ? ORDER BY user_id', [$id])),
        );
        self::assertCount(2, $this->broadcaster()->broadcasts);
        self::assertStringContainsString('id="list_rooms_direct_'.$id.'"', (string) $this->broadcaster()->broadcasts[0]['payload']);
    }

    public function testCreateWithNobodyMakesARoomForOne(): void
    {
        $this->signIn('users.loner');
        $response = $this->submit('POST', '/rooms/directs');
        $id = (int) $this->fetchValue("SELECT MAX(id) FROM rooms WHERE type = 'Rooms::Direct'");

        self::assertRedirectsTo('http://localhost/rooms/'.$id, $response);
        self::assertSame([self::id('users.loner')], array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT user_id FROM memberships WHERE room_id = ?', [$id])));
        self::assertRedirectsTo('http://localhost/rooms/'.$id, $this->submit('POST', '/rooms/directs'));
    }

    public function testNew(): void
    {
        $this->signIn('users.david');
        $response = $this->get('/rooms/directs/new', ['HTTP_TURBO_FRAME' => 'direct_rooms_control']);
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->crawler($response)->filter('turbo-frame#direct_rooms_control form[action="/rooms/directs"]'));
    }

    public function testEditShowsTheOtherMembers(): void
    {
        $this->signIn('users.david');
        $page = $this->crawler($this->get('/rooms/directs/'.self::id('rooms.david_and_jason').'/edit'));
        self::assertSame('Edit settings for Jason', $page->filter('title')->text());
        self::assertSame(['Jason'], $page->filter('.member strong')->each(static fn ($s): string => $s->text()));
        self::assertSame('http://localhost/rooms/directs/'.self::id('rooms.david_and_jason'), $page->filter('form.button_to')->attr('action'));
    }

    public function testEditARoomForOneShowsItsMember(): void
    {
        $this->signIn('users.loner');
        $this->submit('POST', '/rooms/directs');
        $id = (int) $this->fetchValue("SELECT MAX(id) FROM rooms WHERE type = 'Rooms::Direct'");
        $page = $this->crawler($this->get('/rooms/directs/'.$id.'/edit'));
        self::assertSame(['Lonely Lou'], $page->filter('.member strong')->each(static fn ($s): string => $s->text()));
        self::assertSame('Edit settings for Lonely Lou', $page->filter('title')->text());
    }

    public function testSharedRoomsAreOutOfReach(): void
    {
        $this->signIn('users.david');
        self::assertRedirectsTo('http://localhost/', $this->get('/rooms/directs/'.self::id('rooms.hq').'/edit'));
        self::assertRedirectsTo('http://localhost/', $this->submit('DELETE', '/rooms/directs/'.self::id('rooms.hq')));
        self::assertSame(1, (int) $this->fetchValue('SELECT COUNT(*) FROM rooms WHERE id = ?', self::id('rooms.hq')));
    }

    public function testAnyMemberDestroysADirectRoom(): void
    {
        $this->signIn('users.jason');
        $this->broadcaster()->clear();
        $room = self::id('rooms.david_and_jason');
        self::assertRedirectsTo('http://localhost/', $this->submit('DELETE', '/rooms/directs/'.$room));
        self::assertFalse($this->fetchValue('SELECT 1 FROM rooms WHERE id = ?', $room));
        self::assertSame('<turbo-stream action="remove" target="list_rooms_direct_'.$room.'"></turbo-stream>', $this->broadcaster()->broadcasts[0]['payload']);
    }

    public function testShowIsInheritedWithoutSetRoom(): void
    {
        $this->signIn('users.david');
        self::assertSame(500, $this->get('/rooms/directs/'.self::id('rooms.david_and_jason'))->getStatusCode());
    }

    public function testUpdateDoesNotExist(): void
    {
        $this->signIn('users.david');
        self::assertSame(404, $this->submit('PATCH', '/rooms/directs/'.self::id('rooms.david_and_jason'))->getStatusCode());
    }
}
