<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rooms;

/** Rooms::RefreshesController (reference/app/controllers/rooms/refreshes_controller.rb). */
final class RefreshesControllerTest extends RoomsTestCase
{
    private const string TURBO_STREAM = 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml';

    public function testNewMessagesAreAppendedAndUpdatedOnesReplaced(): void
    {
        $this->signIn('users.david');
        $room = self::id('rooms.watercooler');
        $since = (new \DateTimeImmutable('2026-03-02 15:00:00 UTC'))->getTimestamp() * 1000;
        $this->connection()->executeStatement("UPDATE messages SET updated_at = '2026-03-02 15:30:00' WHERE id = ?", [self::id('messages.busy_001')]);
        $response = $this->get('/rooms/'.$room.'/refresh?since='.$since, ['HTTP_ACCEPT' => self::TURBO_STREAM]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/vnd.turbo-stream.html; charset=utf-8', $response->headers->get('Content-Type'));
        $html = (string) $response->getContent();
        self::assertSame(1, substr_count($html, '<turbo-stream action="append" target="messages_rooms_closed_'.$room.'">'));
        $clientId = $this->fetchValue('SELECT client_message_id FROM messages WHERE id = ?', self::id('messages.busy_001'));
        self::assertStringContainsString('<turbo-stream action="replace" target="message_'.$clientId.'">', $html);
    }

    public function testNothingChanged(): void
    {
        $this->signIn('users.david');
        $response = $this->get('/rooms/'.self::id('rooms.watercooler').'/refresh?since=1893456000000', ['HTTP_ACCEPT' => self::TURBO_STREAM]);
        self::assertSame(200, $response->getStatusCode());
        // The view's blank line between its two blocks: Rack::ETag digests it like any body.
        self::assertSame("\n", $response->getContent());
        self::assertSame('W/"'.substr(hash('sha256', "\n"), 0, 32).'"', $response->headers->get('ETag'));
        self::assertSame('max-age=0, private, must-revalidate', $response->headers->get('Cache-Control'));
    }

    public function testUpdatedOnlyIsOneIndentedReplacePerMessage(): void
    {
        $this->signIn('users.david');
        $this->connection()->executeStatement("UPDATE messages SET updated_at = '2030-01-01 00:00:01' WHERE id = ?", [self::id('messages.busy_001')]);
        $response = $this->get('/rooms/'.self::id('rooms.watercooler').'/refresh?since=1893456000000', ['HTTP_ACCEPT' => self::TURBO_STREAM]);

        $html = (string) $response->getContent();
        self::assertStringStartsWith("\n  <turbo-stream action=\"replace\" target=\"message_", $html);
        self::assertStringEndsWith("</template></turbo-stream>\n", $html);
        self::assertSame(1, substr_count($html, '<turbo-stream '));
    }

    public function testOnlyTurboStreamsAreAcceptable(): void
    {
        $this->signIn('users.david');
        self::assertSame(406, $this->get('/rooms/'.self::id('rooms.hq').'/refresh', ['HTTP_ACCEPT' => 'text/html'])->getStatusCode());
        self::assertSame(200, $this->get('/rooms/'.self::id('rooms.hq').'/refresh')->getStatusCode());
    }

    public function testNonMembersGetNotFound(): void
    {
        $this->signIn('users.jz');
        self::assertSame(404, $this->get('/rooms/'.self::id('rooms.watercooler').'/refresh', ['HTTP_ACCEPT' => self::TURBO_STREAM])->getStatusCode());
    }

    public function testSinceThatIsNotAStringFails(): void
    {
        $this->signIn('users.david');
        self::assertSame(500, $this->get('/rooms/'.self::id('rooms.hq').'/refresh?since[]=1', ['HTTP_ACCEPT' => self::TURBO_STREAM])->getStatusCode());
        self::assertSame(200, $this->get('/rooms/'.self::id('rooms.hq').'/refresh?since=99999999999999999999999', ['HTTP_ACCEPT' => self::TURBO_STREAM])->getStatusCode());
    }
}
