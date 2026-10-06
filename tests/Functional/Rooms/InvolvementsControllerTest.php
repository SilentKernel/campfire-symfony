<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rooms;

use App\Cable\StreamNames;

/** Rooms::InvolvementsController (reference/app/controllers/rooms/involvements_controller.rb). */
final class InvolvementsControllerTest extends RoomsTestCase
{
    public function testShowOffersTheNextInvolvement(): void
    {
        $this->signIn('users.david');
        $hq = self::id('rooms.hq');
        $page = $this->crawler($this->get('/rooms/'.$hq.'/involvement', ['HTTP_TURBO_FRAME' => 'involvement_rooms_open_'.$hq]));

        self::assertSame('/rooms/'.$hq.'/involvement?involvement=invisible', $page->filter('form.button_to')->attr('action'));
        self::assertSame('put', $page->filter('input[name="_method"]')->attr('value'));
        self::assertSame('btn nothing', $page->filter('button')->attr('class'));
    }

    public function testDirectRoomsCycleBetweenEverythingAndNothing(): void
    {
        $this->signIn('users.david');
        $room = self::id('rooms.david_and_jason');
        $page = $this->crawler($this->get('/rooms/'.$room.'/involvement'));
        self::assertSame('/rooms/'.$room.'/involvement?involvement=nothing', $page->filter('form.button_to')->attr('action'));
    }

    public function testUpdateChangesTheInvolvement(): void
    {
        $this->signIn('users.david');
        $hq = self::id('rooms.hq');
        $this->broadcaster()->clear();
        $response = $this->submit('PUT', '/rooms/'.$hq.'/involvement?involvement=everything');

        self::assertRedirectsTo('http://localhost/rooms/'.$hq.'/involvement', $response);
        self::assertSame(['everything', '2026-03-02 16:00:00'], array_values((array) $this->connection()->fetchAssociative('SELECT involvement, updated_at FROM memberships WHERE id = ?', [self::id('memberships.david_hq')])));
        self::assertSame([], $this->broadcaster()->broadcasts);
    }

    public function testBecomingInvisibleRemovesTheRoomFromTheSidebar(): void
    {
        $this->signIn('users.david');
        $hq = self::id('rooms.hq');
        $this->broadcaster()->clear();
        $this->submit('PUT', '/rooms/'.$hq.'/involvement', ['involvement' => 'invisible']);

        self::assertSame([['stream' => StreamNames::userRooms(self::id('users.david')), 'payload' => '<turbo-stream action="remove" target="list_rooms_open_'.$hq.'"></turbo-stream>']], $this->broadcaster()->broadcasts);

        $this->broadcaster()->clear();
        $this->submit('PUT', '/rooms/'.$hq.'/involvement', ['involvement' => 'mentions']);
        self::assertSame(StreamNames::userRooms(self::id('users.david')), $this->broadcaster()->broadcasts[0]['stream']);
        self::assertStringStartsWith('<turbo-stream action="prepend" target="shared_rooms"><template><a id="list_rooms_open_'.$hq.'"', (string) $this->broadcaster()->broadcasts[0]['payload']);
    }

    public function testDirectRoomsBroadcastNothing(): void
    {
        $this->signIn('users.david');
        $this->broadcaster()->clear();
        $this->submit('PUT', '/rooms/'.self::id('rooms.david_and_jason').'/involvement', ['involvement' => 'nothing']);
        self::assertSame('nothing', $this->fetchValue('SELECT involvement FROM memberships WHERE id = ?', self::id('memberships.david_david_and_jason')));
        self::assertSame([], $this->broadcaster()->broadcasts);
    }

    public function testBlankInvolvementIsStoredAsNullAndUnknownOnesFail(): void
    {
        $this->signIn('users.david');
        $hq = self::id('rooms.hq');
        self::assertSame(302, $this->submit('PUT', '/rooms/'.$hq.'/involvement?involvement=')->getStatusCode());
        self::assertNull($this->fetchValue('SELECT involvement FROM memberships WHERE id = ?', self::id('memberships.david_hq')));

        // `involvement_previously_was.inquiry` on nil, after the update
        self::assertSame(500, $this->submit('PUT', '/rooms/'.$hq.'/involvement?involvement=everything')->getStatusCode());
        self::assertSame(500, $this->submit('PUT', '/rooms/'.$hq.'/involvement?involvement=bogus')->getStatusCode());
    }

    public function testNonMembersGetNotFound(): void
    {
        $this->signIn('users.jz');
        self::assertSame(404, $this->get('/rooms/'.self::id('rooms.watercooler').'/involvement')->getStatusCode());
        self::assertSame(404, $this->submit('PUT', '/rooms/'.self::id('rooms.watercooler').'/involvement?involvement=nothing')->getStatusCode());
    }

    public function testUpdateNeedsTheAuthenticityToken(): void
    {
        $this->signIn('users.david');
        self::assertSame(422, $this->submitWithoutToken('PUT', '/rooms/'.self::id('rooms.hq').'/involvement?involvement=everything')->getStatusCode());
    }
}
