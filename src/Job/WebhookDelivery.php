<?php

declare(strict_types=1);

namespace App\Job;

use App\Http\Mime;
use App\Rails\RailsJson;
use App\RichText\RichTextRenderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Webhook#deliver (reference/app/models/webhook.rb), up to the reply: posts the message to the
 * bot's URL and turns the answer into what the bot says back in the room.
 *
 * - 200 with text/html or text/plain: the body, as the text of a message;
 * - any other answer with a Content-Type: the body, as an attachment named
 *   `attachment.<Mime symbol>` (Mime::Type.lookup accepts unknown types too, without a symbol);
 * - a 7 second connect or read timeout: "Failed to respond within 7 seconds".
 *
 * There is no PrivateNetworkGuard here, as in Rails: only an administrator sets the URL.
 */
final readonly class WebhookDelivery
{
    public const int ENDPOINT_TIMEOUT = 7;

    public function __construct(
        private HttpClientInterface $httpClient,
        private MessageRecords $messages,
        #[Autowire(service: RichTextRenderer::class)] private ?RichTextRenderer $renderer = null,
    ) {
    }

    /**
     * @param array{id: int, room_id: int, room_type: string, room_name: ?string, creator_id: int, creator_name: string, body: ?string, filename: ?string} $message
     * @param array{id: int, name: string, bot_key: string}                                                                                                $bot
     *
     * @return array{text: string}|array{attachment: string, filename: string, content_type: string}|null
     *
     * @throws TransportExceptionInterface when the endpoint cannot be reached (not a timeout)
     */
    public function deliver(string $url, array $message, array $bot): ?array
    {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $this->payload($message, $bot),
                'timeout' => self::ENDPOINT_TIMEOUT,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $error) {
            if ($error instanceof TimeoutExceptionInterface || self::isTimeout($error)) {
                return ['text' => \sprintf('Failed to respond within %d seconds', self::ENDPOINT_TIMEOUT)];
            }
            throw $error;
        }

        $contentType = self::contentType($headers['content-type'][0] ?? null);
        if (200 === $status && \in_array($contentType, ['text/html', 'text/plain'], true)) {
            return ['text' => $body];
        }
        if (null !== $contentType) {
            // Mime::Type.lookup(content_type): a registered type (synonyms resolve to it) or a new
            // one with no symbol.
            $symbol = Mime::lookup($contentType);

            return [
                'attachment' => $body,
                'filename' => 'attachment.'.($symbol ?? ''),
                'content_type' => null === $symbol ? $contentType : Mime::typeOf($symbol),
            ];
        }

        return null;
    }

    /**
     * Webhook#payload, `to_json`'d (Active Support JSON).
     *
     * @param array{id: int, room_id: int, room_type: string, room_name: ?string, creator_id: int, creator_name: string, body: ?string, filename: ?string} $message
     * @param array{id: int, name: string, bot_key: string}                                                                                                $bot
     */
    public function payload(array $message, array $bot): string
    {
        return RailsJson::encode([
            'user' => ['id' => $message['creator_id'], 'name' => $message['creator_name']],
            'room' => ['id' => $message['room_id'], 'name' => $message['room_name'], 'path' => '/rooms/'.$message['room_id'].'/'.$bot['bot_key'].'/messages'],
            'message' => [
                'id' => $message['id'],
                'body' => [
                    'html' => null === $message['body'] ? null : ($this->renderer?->toHtml($message['body']) ?? $message['body']),
                    'plain' => self::withoutRecipientMentions($this->messages->plainTextBody($message), $bot['name']),
                ],
                'path' => '/rooms/'.$message['room_id'].'/@'.$message['id'],
            ],
        ]);
    }

    /**
     * `without_recipient_mentions`: drops "@<bot name>" (the bot's attachable plain text) and
     * leading/trailing whitespace, Unicode spaces included.
     */
    public static function withoutRecipientMentions(string $plain, string $botName): string
    {
        // Onigmo's \p{Space}: White_Space
        return (string) preg_replace('/\A[\p{Z}\t\n\v\f\r\x{85}]+|[\p{Z}\t\n\v\f\r\x{85}]+\z/u', '', str_replace('@'.$botName, '', $plain));
    }

    /** Net::HTTPHeader#content_type: "main/sub", downcased, without parameters. */
    private static function contentType(?string $header): ?string
    {
        if (null === $header) {
            return null;
        }
        $parts = explode('/', explode(';', $header, 2)[0], 2);
        $main = strtolower(trim($parts[0]));
        if ('' === $main) {
            return null;
        }

        return $main.'/'.strtolower(trim($parts[1] ?? ''));
    }

    private static function isTimeout(TransportExceptionInterface $error): bool
    {
        return 1 === preg_match('/timed? ?out|timeout/i', $error->getMessage());
    }
}
