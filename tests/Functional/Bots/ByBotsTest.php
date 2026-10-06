<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bots;

use App\Cable\Broadcaster;
use App\Cable\RecordingBroadcaster;
use App\Tests\Support\CampfireTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The bot API against what the Rails app answers for the same seed (fixtures/*.json were
 * recorded from campfire-reference:app; its host is replaced by the test client's).
 */
final class ByBotsTest extends CampfireTestCase
{
    private const string KEY = '394959859-BenderBot123';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function pages(): iterable
    {
        yield 'last page' => ['', 'index_.json', '?before=933434601'];
        yield 'before' => ['?before=933434601', 'index_before_933434601_.json', '?before=933434561'];
        yield 'after' => ['?after=933434601', 'index_after_933434601_.json', null];
        yield 'after the last' => ['?after=136976342', 'index_after_136976342_.json', null];
    }

    #[DataProvider('pages')]
    public function testIndexMatchesRails(string $query, string $fixture, ?string $next): void
    {
        $this->client->request('GET', '/rooms/'.self::id('rooms.watercooler').'/'.self::KEY.'/messages'.$query);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('131', $response->headers->get('X-Total-Count'));
        self::assertSame(
            null === $next ? null : '<http://localhost/rooms/486777696/'.self::KEY.'/messages'.$next.'>; rel="next"',
            $response->headers->get('Link'),
        );
        $expected = str_replace('http://127.0.0.1:3277', 'http://localhost', (string) file_get_contents(__DIR__.'/fixtures/'.$fixture));
        self::assertSame($expected, $response->getContent());
    }

    public function testNotAMemberOfTheRoom(): void
    {
        $this->client->request('GET', '/rooms/'.self::id('rooms.hq').'/'.self::KEY.'/messages');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame('text/html', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('', $this->client->getResponse()->getContent());
    }

    public function testUnknownPageAnchorIsNotFound(): void
    {
        $this->client->request('GET', '/rooms/'.self::id('rooms.watercooler').'/'.self::KEY.'/messages?before=1');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testCreateWithTheRawBody(): void
    {
        $room = self::id('rooms.watercooler');
        $this->client->request('POST', '/rooms/'.$room.'/'.self::KEY.'/messages', server: ['CONTENT_TYPE' => 'text/plain'], content: 'Hello <b>world</b> from bender');

        $response = $this->client->getResponse();
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $id = (int) $this->connection()->fetchOne('SELECT MAX(id) FROM messages WHERE creator_id = ?', [self::id('users.bender')]);
        self::assertSame('http://localhost/messages/'.$id, $response->headers->get('Location'));
        self::assertSame('', $response->getContent());
        self::assertStringContainsString('world', (string) $this->connection()->fetchOne("SELECT body FROM action_text_rich_texts WHERE record_type = 'Message' AND record_id = ?", [$id]));

        $streams = array_column($this->broadcaster()->broadcasts, 'stream');
        self::assertContains('Z2lkOi8vY2FtcGZpcmUvUm9vbXM6OkNsb3NlZC80ODY3Nzc2OTY:messages', $streams);
        self::assertContains('user_'.self::id('users.david').'_unreads', $streams);
    }

    public function testCreateWithAnAttachment(): void
    {
        $path = $this->storagePath.'/upload.txt';
        file_put_contents($path, 'hello file');
        $this->client->request('POST', '/rooms/'.self::id('rooms.watercooler').'/'.self::KEY.'/messages', files: ['attachment' => new UploadedFile($path, 'notes.txt', 'text/plain', test: true)]);

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $id = (int) $this->connection()->fetchOne('SELECT MAX(id) FROM messages WHERE creator_id = ?', [self::id('users.bender')]);
        self::assertSame('notes.txt', $this->connection()->fetchOne(
            "SELECT b.filename FROM active_storage_attachments a JOIN active_storage_blobs b ON b.id = a.blob_id WHERE a.record_type = 'Message' AND a.record_id = ?",
            [$id],
        ));
    }

    public function testCreateWithoutABodyIsUnprocessable(): void
    {
        $this->client->request('POST', '/rooms/'.self::id('rooms.watercooler').'/'.self::KEY.'/messages', content: "  \n");

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('text/html', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testUpdateOwnMessage(): void
    {
        $room = self::id('rooms.watercooler');
        $message = 933434630; // bot_in_watercooler, by Bender
        $this->client->request('PATCH', '/rooms/'.$room.'/'.self::KEY.'/messages/'.$message, content: 'Edited <i>body</i>');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
        $json = json_decode((string) $response->getContent(), true);
        self::assertSame(['id', 'created_at', 'body', 'creator', 'room', 'url'], array_keys($json));
        self::assertSame($message, $json['id']);
        self::assertSame('Edited body', $json['body']['plain_text']);
        self::assertStringContainsString('Edited <i>body</i>', $json['body']['html']);
        self::assertSame(['id', 'name', 'role', 'avatar_url'], array_keys($json['creator']));
        self::assertSame('bot', $json['creator']['role']);
        self::assertSame('http://localhost/rooms/'.$room.'/messages/'.$message, $json['url']);
        self::assertContains('replace', array_map(static fn (array $b): string => \is_string($b['payload']) && str_contains($b['payload'], 'action="replace"') ? 'replace' : '', $this->broadcaster()->broadcasts));
    }

    public function testCannotUpdateOrDestroyOthersMessages(): void
    {
        $uri = '/rooms/'.self::id('rooms.watercooler').'/'.self::KEY.'/messages/933434601';
        $this->client->request('PATCH', $uri, content: 'x');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame('text/html', $this->client->getResponse()->headers->get('Content-Type'));
        $this->client->request('DELETE', $uri);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('PATCH', '/rooms/'.self::id('rooms.watercooler').'/'.self::KEY.'/messages/1', content: 'x');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testDestroyOwnMessage(): void
    {
        $this->client->request('DELETE', '/rooms/'.self::id('rooms.watercooler').'/'.self::KEY.'/messages/933434630');

        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        self::assertSame('', $this->client->getResponse()->getContent());
        self::assertFalse($this->connection()->fetchOne('SELECT id FROM messages WHERE id = 933434630'));
        self::assertSame('Z2lkOi8vY2FtcGZpcmUvUm9vbXM6OkNsb3NlZC80ODY3Nzc2OTY:messages', $this->broadcaster()->broadcasts[0]['stream'] ?? null);
    }

    public function testBoostsLikeRails(): void
    {
        $base = '/rooms/'.self::id('rooms.watercooler').'/'.self::KEY.'/messages/933434601/boosts';
        $this->client->request('POST', $base, content: '🎉');

        $response = $this->client->getResponse();
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
        $json = json_decode((string) $response->getContent(), true);
        self::assertSame(['id', 'content', 'created_at', 'booster', 'message'], array_keys($json));
        self::assertSame('🎉', $json['content']);
        self::assertSame(['id' => 933434601, 'url' => 'http://localhost/rooms/486777696/messages/933434601'], $json['message']);
        self::assertSame(self::id('users.bender'), $json['booster']['id']);

        $this->client->request('DELETE', $base.'/'.$json['id']);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->connection()->fetchOne('SELECT id FROM boosts WHERE id = ?', [$json['id']]));

        $this->client->request('DELETE', $base.'/'.self::id('boosts.first'));
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), "someone else's boost");
        $this->client->request('POST', $base, content: '');
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/rooms/'.self::id('rooms.watercooler').'/'.self::KEY.'/messages/1/boosts', content: 'x');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    private function broadcaster(): RecordingBroadcaster
    {
        $broadcaster = static::getContainer()->get(Broadcaster::class);
        \assert($broadcaster instanceof RecordingBroadcaster);

        return $broadcaster;
    }
}
