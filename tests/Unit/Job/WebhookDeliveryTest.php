<?php

declare(strict_types=1);

namespace App\Tests\Unit\Job;

use App\Domain\Messages\MessageRichText;
use App\Job\MessageRecords;
use App\Job\WebhookDelivery;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebhookDeliveryTest extends TestCase
{
    private const array MESSAGE = [
        'id' => 9, 'room_id' => 3, 'room_type' => 'Rooms::Closed', 'room_name' => 'All <Talk>', 'creator_id' => 1, 'creator_name' => 'David',
        'body' => '<p>@Bender Bot what\'s up?&nbsp;</p>', 'filename' => null,
    ];
    private const array BOT = ['id' => 5, 'name' => 'Bender Bot', 'bot_key' => '5-abc'];

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests = [];

    public function testPostsThePayloadAsJson(): void
    {
        $delivery = $this->delivery(new MockResponse('', ['http_code' => 204]));

        self::assertNull($delivery->deliver('https://bot.example/hook', self::MESSAGE, self::BOT));
        [$method, $url, $options] = $this->requests[0];
        self::assertSame('POST', $method);
        self::assertSame('https://bot.example/hook', $url);
        self::assertContains('Content-Type: application/json', $options['headers']);
        self::assertSame(7.0, (float) $options['timeout']);
        self::assertSame(0, $options['max_redirects']);
        self::assertSame(
            '{"user":{"id":1,"name":"David"},"room":{"id":3,"name":"All \u003cTalk\u003e","path":"/rooms/3/5-abc/messages"},'
            .'"message":{"id":9,"body":{"html":"\u003cp\u003e@Bender Bot what\'s up?\u0026nbsp;\u003c/p\u003e","plain":"what\'s up?"},"path":"/rooms/3/@9"}}',
            $options['body'],
        );
    }

    /** @return iterable<string, array{MockResponse, array<string, string>|null}> */
    public static function replies(): iterable
    {
        yield 'text/plain' => [new MockResponse('Hi there', ['response_headers' => ['content-type' => 'text/plain']]), ['text' => 'Hi there']];
        yield 'text/html with charset' => [new MockResponse('<b>Hi</b>', ['response_headers' => ['content-type' => 'Text/HTML; charset=utf-8']]), ['text' => '<b>Hi</b>']];
        yield 'an image' => [new MockResponse("\x89PNG", ['response_headers' => ['content-type' => 'image/png']]), ['attachment' => "\x89PNG", 'filename' => 'attachment.png', 'content_type' => 'image/png']];
        yield 'a synonym' => [new MockResponse('{}', ['response_headers' => ['content-type' => 'text/x-json']]), ['attachment' => '{}', 'filename' => 'attachment.json', 'content_type' => 'application/json']];
        yield 'an unknown type' => [new MockResponse('x', ['response_headers' => ['content-type' => 'application/x-thing']]), ['attachment' => 'x', 'filename' => 'attachment.', 'content_type' => 'application/x-thing']];
        yield 'an error page' => [new MockResponse('oops', ['http_code' => 500, 'response_headers' => ['content-type' => 'text/html']]), ['attachment' => 'oops', 'filename' => 'attachment.html', 'content_type' => 'text/html']];
        yield 'no content type' => [new MockResponse('ok'), null];
    }

    /** @param array<string, string>|null $expected */
    #[DataProvider('replies')]
    public function testReplies(MockResponse $response, ?array $expected): void
    {
        self::assertSame($expected, $this->delivery($response)->deliver('https://bot.example/hook', self::MESSAGE, self::BOT));
    }

    public function testATimeoutBecomesTheReply(): void
    {
        $delivery = $this->delivery(static fn () => throw new TimeoutException('Idle timeout reached for "https://bot.example/hook".'));

        self::assertSame(['text' => 'Failed to respond within 7 seconds'], $delivery->deliver('https://bot.example/hook', self::MESSAGE, self::BOT));
    }

    public function testOtherFailuresRaise(): void
    {
        $delivery = $this->delivery(static fn () => throw new TransportException('Connection refused for "https://bot.example/hook".'));

        $this->expectException(TransportException::class);
        $delivery->deliver('https://bot.example/hook', self::MESSAGE, self::BOT);
    }

    public function testWithoutRecipientMentions(): void
    {
        self::assertSame('hello  there', WebhookDelivery::withoutRecipientMentions("\u{00A0} @Bot hello @Bot there\u{3000}\n", 'Bot'));
    }

    private function delivery(MockResponse|\Closure $response): WebhookDelivery
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = [$method, $url, $options];

            return $response instanceof \Closure ? $response() : $response;
        });
        $noServices = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \LogicException($id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $records = new MessageRecords(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), new MessageRichText($noServices));

        return new WebhookDelivery($client, $records);
    }
}
