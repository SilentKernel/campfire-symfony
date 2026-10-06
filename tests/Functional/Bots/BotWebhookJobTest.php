<?php

declare(strict_types=1);

namespace App\Tests\Functional\Bots;

use App\Cable\Broadcaster;
use App\Cable\RecordingBroadcaster;
use App\Domain\Messages\MessageCreator;
use App\Job\BotWebhookJob;
use App\Job\BotWebhookJobHandler;
use App\Job\MessageRecords;
use App\Job\WebhookDelivery;
use App\RichText\RichTextRenderer;
use App\Storage\BlobService;
use App\Tests\Support\CampfireTestCase;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** Bot::WebhookJob end to end: the post to the bot, and its reply posted to the room. */
final class BotWebhookJobTest extends CampfireTestCase
{
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests = [];

    public function testATextReplyBecomesTheBotsMessage(): void
    {
        $message = self::id('messages.mention');
        $this->handler(new MockResponse('Pong <b>!</b>', ['response_headers' => ['content-type' => 'text/plain; charset=utf-8']]))(new BotWebhookJob(self::id('users.bender'), $message));

        [$method, $url, $options] = $this->requests[0];
        self::assertSame(['POST', 'http://example.com/bender'], [$method, $url]);
        $payload = json_decode((string) $options['body'], true);
        self::assertSame(['id' => self::id('users.david'), 'name' => 'David'], $payload['user']);
        self::assertSame('/rooms/'.self::id('rooms.designers').'/394959859-BenderBot123/messages', $payload['room']['path']);
        self::assertSame('/rooms/'.self::id('rooms.designers').'/@'.$message, $payload['message']['path']);
        self::assertSame('Hey @Kevin, can you check the table above?', $payload['message']['body']['plain']);

        $reply = $this->lastMessageBy('bender');
        self::assertSame(self::id('rooms.designers'), (int) $reply['room_id']);
        self::assertStringContainsString('Pong <b>!</b>', (string) $reply['body']);
        self::assertContains('Z2lkOi8vY2FtcGZpcmUvUm9vbXM6OkNsb3NlZC82NTQ2MzI4NzY:messages', array_column($this->broadcaster()->broadcasts, 'stream'));
    }

    public function testAReplyNotifiesNoOtherBot(): void
    {
        // Another bot with a webhook in Bender's direct room would get every message posted there,
        // but Webhook#receive_text_reply_to doesn't run MessagesController#deliver_webhooks_to_bots.
        $room = self::id('rooms.bender_and_kevin');
        $deployBot = self::id('users.deploy_bot');
        $this->connection()->insert('webhooks', ['user_id' => $deployBot, 'url' => 'http://example.com/deploy', 'created_at' => '2026-03-02 15:00:00', 'updated_at' => '2026-03-02 15:00:00']);
        $this->connection()->insert('memberships', ['room_id' => $room, 'user_id' => $deployBot, 'involvement' => 'everything', 'created_at' => '2026-03-02 15:00:00', 'updated_at' => '2026-03-02 15:00:00']);
        $message = (int) $this->connection()->fetchOne('SELECT id FROM messages WHERE room_id = ?', [$room]);

        $this->handler(new MockResponse('Pong', ['response_headers' => ['content-type' => 'text/plain']]))(new BotWebhookJob(self::id('users.bender'), $message));

        self::assertSame(self::id('rooms.bender_and_kevin'), (int) $this->lastMessageBy('bender')['room_id']);
        $transport = static::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        self::assertSame([], array_filter($transport->getSent(), static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof BotWebhookJob));
    }

    public function testThePayloadMatchesRails(): void
    {
        $records = static::getContainer()->get(MessageRecords::class);
        $renderer = static::getContainer()->get(RichTextRenderer::class);
        \assert($records instanceof MessageRecords && $renderer instanceof RichTextRenderer);
        $delivery = new WebhookDelivery(new MockHttpClient(), $records, $renderer);
        $bot = ['id' => self::id('users.bender'), 'name' => 'Bender Bot', 'bot_key' => '394959859-BenderBot123'];

        // recorded with `Webhook#payload` in campfire-reference:app
        foreach (file(__DIR__.'/fixtures/webhook_payloads.jsonl', \FILE_IGNORE_NEW_LINES) ?: [] as $expected) {
            $id = json_decode($expected, true)['message']['id'];
            $message = $records->find($id);
            self::assertNotNull($message);
            self::assertSame($expected, $delivery->payload($message, $bot), "message $id");
        }
    }

    public function testAnImageReplyBecomesAnAttachment(): void
    {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $this->handler(new MockResponse($png, ['response_headers' => ['content-type' => 'image/png']]))(new BotWebhookJob(self::id('users.bender'), self::id('messages.mention')));

        $reply = $this->lastMessageBy('bender');
        self::assertNull($reply['body']);
        self::assertSame('attachment.png', $reply['filename']);
    }

    public function testATimeoutIsReported(): void
    {
        $this->handler(static fn () => throw new TimeoutException('Idle timeout reached for "http://example.com/bender".'))(new BotWebhookJob(self::id('users.bender'), self::id('messages.mention')));

        self::assertStringContainsString('Failed to respond within 7 seconds', (string) $this->lastMessageBy('bender')['body']);
    }

    public function testAnUnreachableBotFailsTheJobWithoutRetries(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->handler(static fn () => throw new TransportException('Connection refused'))(new BotWebhookJob(self::id('users.bender'), self::id('messages.mention')));
    }

    public function testNothingToDoWithoutAWebhook(): void
    {
        $this->handler(new MockResponse('x'))(new BotWebhookJob(self::id('users.deploy_bot'), self::id('messages.mention')));

        self::assertSame([], $this->requests);
    }

    private function handler(MockResponse|\Closure $response): BotWebhookJobHandler
    {
        $container = static::getContainer();
        $records = $container->get(MessageRecords::class);
        $creator = $container->get(MessageCreator::class);
        $blobs = $container->get(BlobService::class);
        $renderer = $container->get(RichTextRenderer::class);
        \assert($records instanceof MessageRecords && $creator instanceof MessageCreator && $blobs instanceof BlobService && $renderer instanceof RichTextRenderer);
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = [$method, $url, $options];

            return $response instanceof \Closure ? $response() : $response;
        });

        return new BotWebhookJobHandler($this->connection(), $this->em(), $records, new WebhookDelivery($http, $records, $renderer), $creator, $blobs);
    }

    /** @return array{room_id: int|string, body: ?string, filename: ?string} */
    private function lastMessageBy(string $user): array
    {
        $records = static::getContainer()->get(MessageRecords::class);
        \assert($records instanceof MessageRecords);
        $id = $this->connection()->fetchOne('SELECT MAX(id) FROM messages WHERE creator_id = ?', [self::id('users.'.$user)]);
        $record = $records->find((int) $id);
        self::assertNotNull($record);

        return $record;
    }

    private function broadcaster(): RecordingBroadcaster
    {
        $broadcaster = static::getContainer()->get(Broadcaster::class);
        \assert($broadcaster instanceof RecordingBroadcaster);

        return $broadcaster;
    }
}
