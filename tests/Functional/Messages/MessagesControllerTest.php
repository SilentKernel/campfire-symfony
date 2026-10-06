<?php

declare(strict_types=1);

namespace App\Tests\Functional\Messages;

use App\Cable\StreamNames;
use App\Job\BotWebhookJob;
use App\Job\PushMessageJob;
use App\Rails\SignedGlobalId;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * MessagesController (reference/app/controllers/messages_controller.rb): formats, statuses,
 * access rules, the search index, unread memberships and broadcasts.
 */
final class MessagesControllerTest extends MessagesTestCase
{
    private const string TURBO_ACCEPT = 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml';

    public function testCreateRespondsWithTheMessageAsRailsDoes(): void
    {
        $this->signIn('users.david');
        $watercooler = self::id('rooms.watercooler');
        $response = $this->submit('POST', "/rooms/{$watercooler}/messages", ['message' => [
            'body' => '<p>Hello parity world</p>',
            'client_message_id' => '11111111-2222-3333-4444-555555555555',
        ]], ['HTTP_ACCEPT' => self::TURBO_ACCEPT]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/vnd.turbo-stream.html; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('Accept', $response->headers->get('Vary'));
        // The Rails response was captured at another time: timestamps aside, the same bytes. The
        // partial comes from the broadcast's rendering, whose URLs carry the host without a port.
        self::assertSameHtml(self::withoutTimes(str_replace('http://127.0.0.1/', 'http://localhost/', self::railsFixture('create.turbo_stream.html'))), self::withoutTimes(self::normalize((string) $response->getContent())));
    }

    public function testCreateStoresTheMessageAndItsSideEffects(): void
    {
        $this->signIn('users.david');
        $room = self::id('rooms.watercooler');
        $this->connection()->update('memberships', ['connected_at' => '2026-03-02 15:59:30'], ['id' => self::id('memberships.jason_watercooler')]);
        $this->connection()->update('memberships', ['connected_at' => null, 'unread_at' => null], ['id' => self::id('memberships.bender_watercooler')]);
        $this->broadcaster()->clear();

        $response = $this->submit('POST', "/rooms/{$room}/messages", ['message' => ['body' => '<p>Zanzibar at sunrise</p>']], ['HTTP_ACCEPT' => self::TURBO_ACCEPT]);
        self::assertSame(200, $response->getStatusCode());

        $message = $this->connection()->fetchAssociative('SELECT * FROM messages WHERE room_id = ? ORDER BY id DESC LIMIT 1', [$room]);
        self::assertIsArray($message);
        self::assertSame(self::id('users.david'), (int) $message['creator_id']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $message['client_message_id']);
        self::assertSame('2026-03-02 16:00:00', $message['created_at']);
        self::assertSame('<p>Zanzibar at sunrise</p>', $this->fetchValue("SELECT body FROM action_text_rich_texts WHERE record_type = 'Message' AND record_id = ?", $message['id']));
        self::assertSame('Zanzibar at sunrise', $this->fetchValue('SELECT body FROM message_search_index WHERE rowid = ?', $message['id']));
        self::assertSame('2026-03-02 16:00:00', $this->fetchValue('SELECT updated_at FROM rooms WHERE id = ?', $room));

        // Room#receive: the disconnected, visible memberships of everyone but the creator.
        self::assertSame('2026-03-02 16:00:00', $this->fetchValue('SELECT unread_at FROM memberships WHERE id = ?', self::id('memberships.bender_watercooler')));
        self::assertSame('2026-03-02 15:40:00', $this->fetchValue('SELECT unread_at FROM memberships WHERE id = ?', self::id('memberships.david_watercooler')), 'The creator is left alone.');
        self::assertNotSame('2026-03-02 16:00:00', $this->fetchValue('SELECT unread_at FROM memberships WHERE id = ?', self::id('memberships.jason_watercooler')));

        $broadcasts = $this->broadcaster()->broadcasts;
        $roomStream = StreamNames::roomMessages($this->em()->find(\App\Entity\Room::class, $room) ?? throw new \LogicException());
        self::assertSame($roomStream, $broadcasts[0]['stream']);
        self::assertIsString($broadcasts[0]['payload']);
        self::assertStringStartsWith('<turbo-stream action="append" target="messages_rooms_closed_'.$room.'"><template>'."\n".'  <div id="message_'.$message['client_message_id'].'"', $broadcasts[0]['payload']);
        self::assertStringNotContainsString('authenticity_token', $broadcasts[0]['payload'], 'Broadcasts render without a request.');
        $unread = array_slice($broadcasts, 1);
        $members = array_map('intval', $this->connection()->fetchFirstColumn('SELECT user_id FROM memberships WHERE room_id = ?', [$room]));
        self::assertSame(array_map(StreamNames::unreadRooms(...), $members), array_column($unread, 'stream'));
        foreach ($unread as $broadcast) {
            self::assertSame(['roomId' => $room], $broadcast['payload']);
        }
    }

    public function testCreateEnqueuesPushNotificationsAndWebhooksForBotsInADirectRoom(): void
    {
        $this->signIn('users.kevin');
        $room = self::id('rooms.bender_and_kevin');
        $this->submit('POST', "/rooms/{$room}/messages", ['message' => ['body' => 'Hi bot']], ['HTTP_ACCEPT' => self::TURBO_ACCEPT]);

        $id = (int) $this->fetchValue('SELECT MAX(id) FROM messages WHERE room_id = ?', $room);
        self::assertEquals([new PushMessageJob($id), new BotWebhookJob(self::id('users.bender'), $id)], $this->sentJobs());
    }

    public function testCreateEnqueuesWebhooksForMentionedBotsOnly(): void
    {
        $this->signIn('users.david');
        $room = self::id('rooms.watercooler');
        $sgid = static::getContainer()->get(SignedGlobalId::class)->generate('User', self::id('users.bender'));
        $mention = '<action-text-attachment sgid="'.$sgid.'" content-type="application/vnd.campfire.mention"></action-text-attachment>';

        // (Services, the in-memory queue included, are reset between requests.)
        $this->submit('POST', "/rooms/{$room}/messages", ['message' => ['body' => '<p>No mention</p>']], ['HTTP_ACCEPT' => self::TURBO_ACCEPT]);
        $plain = (int) $this->fetchValue('SELECT MAX(id) FROM messages WHERE room_id = ?', $room);
        self::assertEquals([new PushMessageJob($plain)], $this->sentJobs());

        $this->submit('POST', "/rooms/{$room}/messages", ['message' => ['body' => "<p>Hey {$mention}</p>"]], ['HTTP_ACCEPT' => self::TURBO_ACCEPT]);
        $mentioning = (int) $this->fetchValue('SELECT MAX(id) FROM messages WHERE room_id = ?', $room);
        self::assertEquals([new PushMessageJob($mentioning), new BotWebhookJob(self::id('users.bender'), $mentioning)], $this->sentJobs());
    }

    public function testCreateKeepsTheClientMessageId(): void
    {
        $this->signIn('users.david');
        $room = self::id('rooms.watercooler');
        $this->submit('POST', "/rooms/{$room}/messages", ['message' => ['body' => 'Plain', 'client_message_id' => 'abc-123']], ['HTTP_ACCEPT' => self::TURBO_ACCEPT]);

        self::assertSame('Plain', $this->fetchValue("SELECT body FROM message_search_index WHERE rowid = (SELECT id FROM messages WHERE client_message_id = 'abc-123')"));
    }

    public function testCreateWithAnUploadRendersTheImage(): void
    {
        $this->signIn('users.david');
        $room = self::id('rooms.watercooler');
        // The seed's moon.jpg (640x640), uploaded again.
        $key = (string) $this->fetchValue("SELECT key FROM active_storage_blobs WHERE filename = 'moon.jpg'");
        $upload = $this->storagePath.'/upload.jpg';
        copy($this->storagePath.'/files/'.substr($key, 0, 2).'/'.substr($key, 2, 2).'/'.$key, $upload);
        $response = $this->submit('POST', "/rooms/{$room}/messages", ['message' => ['client_message_id' => 'upload-1']], ['HTTP_ACCEPT' => self::TURBO_ACCEPT], [
            'message' => ['attachment' => new UploadedFile($upload, 'upload.jpg', 'image/jpeg', null, true)],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $html = (string) $response->getContent();
        self::assertStringContainsString('<div id="message_upload-1"', $html);
        self::assertMatchesRegularExpression('#<img width="640" height="640" class="message__attachment" loading="lazy" src="/rails/active_storage/representations/redirect/[^"]+/upload\.jpg" />#', $html);
        $id = $this->fetchValue("SELECT id FROM messages WHERE client_message_id = 'upload-1'");
        self::assertSame('upload.jpg', $this->fetchValue('SELECT body FROM message_search_index WHERE rowid = ?', $id));
        self::assertNotFalse($this->fetchValue("SELECT 1 FROM active_storage_attachments WHERE record_type = 'Message' AND record_id = ? AND name = 'attachment'", $id));
    }

    public function testCreateInARoomThatIsGoneRendersRoomNotFound(): void
    {
        $this->signIn('users.david');
        $response = $this->submit('POST', '/rooms/999/messages', ['message' => ['body' => 'x']], ['HTTP_ACCEPT' => self::TURBO_ACCEPT]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('This room was deleted.', (string) $response->getContent());
    }

    public function testCreateWithoutMessageParamsIs400(): void
    {
        $this->signIn('users.david');
        $response = $this->submit('POST', '/rooms/'.self::id('rooms.watercooler').'/messages', [], ['HTTP_ACCEPT' => self::TURBO_ACCEPT]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testCreateWithoutTokenIs422(): void
    {
        $this->signIn('users.david');
        $this->client->request('POST', '/rooms/'.self::id('rooms.watercooler').'/messages', ['message' => ['body' => 'x']]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testSignedOutIsRedirectedToSignIn(): void
    {
        $response = $this->get('/rooms/'.self::id('rooms.watercooler').'/messages');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('http://localhost/session/new', $response->headers->get('Location'));
    }

    public function testIndexOfARoomOutsideTheMembershipsIs404(): void
    {
        $this->signIn('users.jz');

        self::assertSame(404, $this->get('/rooms/'.self::id('rooms.watercooler').'/messages')->getStatusCode());
        self::assertSame(404, $this->get('/messages')->getStatusCode());
    }

    public function testIndexPagesAndConditionalGet(): void
    {
        $this->signIn('users.david');
        $room = self::id('rooms.designers');

        $response = $this->get("/rooms/{$room}/messages");
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('max-age=0, private, must-revalidate', $response->headers->get('Cache-Control'));
        $etag = (string) $response->headers->get('ETag');
        self::assertMatchesRegularExpression('#\AW/"[0-9a-f]{32}"\z#', $etag);
        self::assertSame('Mon, 02 Mar 2026 15:53:00 GMT', $response->headers->get('Last-Modified'));

        $notModified = $this->get("/rooms/{$room}/messages", ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertSame(304, $notModified->getStatusCode());
        self::assertSame('', $notModified->getContent());

        $first = self::id('messages.plain');
        self::assertSame(204, $this->get("/rooms/{$room}/messages?before={$first}")->getStatusCode());
        self::assertSame(404, $this->get("/rooms/{$room}/messages?before=1")->getStatusCode());
        self::assertSame(406, $this->get("/rooms/{$room}/messages", ['HTTP_ACCEPT' => 'application/json'])->getStatusCode());
    }

    public function testShowAndEdit(): void
    {
        $this->signIn('users.kevin');
        $room = self::id('rooms.designers');
        $davids = self::id('messages.edited');
        $jasons = self::id('messages.emoji');

        self::assertSame(200, $this->get("/rooms/{$room}/messages/{$davids}")->getStatusCode());
        self::assertSame(403, $this->get("/rooms/{$room}/messages/{$davids}/edit")->getStatusCode());
        self::assertSame(200, $this->get("/rooms/{$room}/messages/{$jasons}/edit")->getStatusCode());
        self::assertSame(404, $this->get("/rooms/{$room}/messages/".self::id('messages.fourth'))->getStatusCode());
    }

    public function testUpdateReindexesTouchesAndBroadcasts(): void
    {
        $this->signIn('users.david');
        $room = self::id('rooms.designers');
        $id = self::id('messages.edited');
        $this->broadcaster()->clear();

        $response = $this->submit('PATCH', "/rooms/{$room}/messages/{$id}", ['message' => ['body' => '<p>Launch moved to the 23rd.</p>']], ['HTTP_ACCEPT' => self::TURBO_ACCEPT]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame("http://localhost/rooms/{$room}/messages/{$id}", $response->headers->get('Location'));
        // Only render sets `Vary: Accept` (_set_vary_header), not redirect_to.
        self::assertFalse($response->headers->has('Vary'));
        self::assertSame('Launch moved to the 23rd.', $this->fetchValue('SELECT body FROM message_search_index WHERE rowid = ?', $id));
        self::assertSame('2026-03-02 16:00:00', $this->fetchValue('SELECT updated_at FROM messages WHERE id = ?', $id));
        self::assertSame('2026-03-02 16:00:00', $this->fetchValue('SELECT updated_at FROM rooms WHERE id = ?', $room));

        $broadcast = $this->broadcaster()->broadcasts[0];
        self::assertIsString($broadcast['payload']);
        $clientId = $this->fetchValue('SELECT client_message_id FROM messages WHERE id = ?', $id);
        self::assertSame(
            '<turbo-stream maintain_scroll="true" action="replace" target="presentation_message_'.$clientId.'"><template><div id="presentation_message_'.$clientId.'" dir="auto" data-reply-target="body" data-messages-target="body">'
            ."\n  <div class=\"lexxy-content\">\n  <p>Launch moved to the 23rd.</p>\n</div>\n\n</div>\n</template></turbo-stream>",
            $broadcast['payload'],
        );
    }

    public function testOnlyTheCreatorOrAnAdministratorMayUpdateOrDestroy(): void
    {
        $this->signIn('users.kevin');
        $room = self::id('rooms.designers');
        $id = self::id('messages.edited');

        self::assertSame(403, $this->submit('PATCH', "/rooms/{$room}/messages/{$id}", ['message' => ['body' => 'mine now']])->getStatusCode());
        self::assertSame(403, $this->submit('DELETE', "/rooms/{$room}/messages/{$id}", [], ['HTTP_ACCEPT' => self::TURBO_ACCEPT])->getStatusCode());
        self::assertNotFalse($this->fetchValue('SELECT 1 FROM messages WHERE id = ?', $id));
    }

    public function testDestroyRemovesEverythingAndBroadcasts(): void
    {
        $this->signIn('users.david');
        $room = self::id('rooms.designers');
        $id = self::id('messages.boosted_by_david');
        $clientId = $this->fetchValue('SELECT client_message_id FROM messages WHERE id = ?', $id);
        $this->broadcaster()->clear();

        $response = $this->submit('DELETE', "/rooms/{$room}/messages/{$id}", [], ['HTTP_ACCEPT' => self::TURBO_ACCEPT]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame("<turbo-stream action=\"remove\" target=\"message_{$clientId}\"></turbo-stream>\n", $response->getContent());
        self::assertFalse($this->fetchValue('SELECT 1 FROM messages WHERE id = ?', $id));
        self::assertFalse($this->fetchValue('SELECT 1 FROM boosts WHERE message_id = ?', $id));
        self::assertFalse($this->fetchValue("SELECT 1 FROM action_text_rich_texts WHERE record_type = 'Message' AND record_id = ?", $id));
        self::assertFalse($this->fetchValue('SELECT 1 FROM message_search_index WHERE rowid = ?', $id));
        self::assertSame("<turbo-stream action=\"remove\" target=\"message_{$clientId}\"></turbo-stream>", $this->broadcaster()->broadcasts[0]['payload']);
    }

    /** @return list<object> */
    private function sentJobs(): array
    {
        $transport = $this->client->getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return array_values(array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $job): bool => $job instanceof PushMessageJob || $job instanceof BotWebhookJob,
        ));
    }

    /** Timestamps (datetime attributes and epoch milliseconds) blanked. */
    private static function withoutTimes(string $html): string
    {
        $html = (string) preg_replace('/datetime="[^"]+"/', 'datetime="T"', $html);

        return (string) preg_replace('/(data-message-timestamp|data-message-updated-at|data-sort-value)="\d+"/', '$1="T"', $html);
    }
}
