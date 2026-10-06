<?php

declare(strict_types=1);

namespace App\Push;

use App\Opengraph\PrivateNetworkGuard;
use App\Rails\Base64;
use App\Rails\RailsJson;
use Doctrine\DBAL\Connection;
use Minishlink\WebPush\Subscription;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * WebPush::Pool (reference/lib/web_push/pool.rb) with WebPush::Notification
 * (reference/lib/web_push/notification.rb) and Push::Subscription#notification: delivers one
 * payload to many subscriptions, concurrently, and deletes the subscriptions the push service
 * reports gone (404/410) or whose keys cannot be used (Rails: WebPush::ExpiredSubscription and
 * OpenSSL errors call the invalid subscription handler).
 *
 * Each endpoint is checked at the point of use: https on port 443, a permitted push service, and
 * a public address (PrivateNetworkGuard), to which the connection is then pinned (HttpClient's
 * `resolve`), so DNS cannot be rebound to a private address between the check and the request.
 */
final class WebPushPool
{
    public const array PERMITTED_ENDPOINT_HOSTS = [
        'jmt17.google.com',
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'web.push.apple.com',
        'notify.windows.com',
    ];

    /** reference/lib/web_push/notification.rb `vapid_identification`. */
    public const string VAPID_SUBJECT = 'mailto:support@37signals.com';

    /** `account_logo_path`, the notification icon. */
    public const string ICON_PATH = '/account/logo';

    private const float TIMEOUT = 30.0;

    public function __construct(
        private readonly Connection $connection,
        private readonly PrivateNetworkGuard $guard,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%campfire.vapid_public_key%')] private readonly string $vapidPublicKey,
        #[Autowire('%campfire.vapid_private_key%')] private readonly string $vapidPrivateKey,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * `WebPush::Pool#queue(payload, subscriptions)`: one notification per subscription.
     *
     * @param array{title: string, body: string, path: string}                                                                $payload
     * @param iterable<array{id: int|string, user_id: int|string, endpoint: ?string, p256dh_key: ?string, auth_key: ?string}> $subscriptions
     *
     * @return int the notifications sent
     */
    public function queue(array $payload, iterable $subscriptions): int
    {
        $notifications = [];
        $resolved = [];
        foreach ($subscriptions as $subscription) {
            $host = self::permittedEndpointHost($subscription['endpoint']);
            if (null === $host) {
                continue;
            }
            // `resolved_endpoint_ip`, once per host and batch.
            $ip = \array_key_exists($host, $resolved) ? $resolved[$host] : ($resolved[$host] = $this->guard->resolve($host));
            if (null === $ip) {
                continue;
            }
            $keys = self::usableKeys($subscription['p256dh_key'], $subscription['auth_key']);
            if (null === $keys) {
                // web-push's Base64.urlsafe_decode64 raises ArgumentError, which WebPush::Pool only
                // logs: the subscription is kept (reference/lib/web_push/pool.rb).
                $this->logger->error('Error in WebPush::Pool.deliver: ArgumentError invalid base64');
                continue;
            }
            if (false === $keys) {
                // A decodable key that isn't a P-256 point is an OpenSSL error: Rails invalidates it.
                $this->invalidate((int) $subscription['id']);
                continue;
            }
            $notifications[] = [$subscription, $this->message($payload, (int) $subscription['user_id'])];
        }
        if ([] === $notifications) {
            return 0;
        }

        $client = $this->httpClient->withOptions(['resolve' => array_filter($resolved), 'timeout' => self::TIMEOUT, 'max_redirects' => 0]);
        $webPush = $this->webPush();
        $responses = [];
        foreach ($notifications as [$subscription, $message]) {
            try {
                $request = $webPush->request(Subscription::create([
                    'endpoint' => (string) $subscription['endpoint'],
                    'publicKey' => (string) $subscription['p256dh_key'],
                    'authToken' => (string) $subscription['auth_key'],
                    'contentEncoding' => 'aes128gcm',
                ]), $message, ['urgency' => 'high']);
            } catch (\Throwable) {
                // Rails: an OpenSSL error while encrypting invalidates the subscription.
                $this->invalidate((int) $subscription['id']);
                continue;
            }
            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                $headers[$name] = implode(', ', $values);
            }
            // Requests are lazy: they all go out together, and are read below as they complete.
            $responses[(int) $subscription['id']] = $client->request($request->getMethod(), (string) $request->getUri(), [
                'headers' => $headers,
                'body' => (string) $request->getBody(),
            ]);
        }

        foreach ($responses as $id => $response) {
            try {
                $status = $response->getStatusCode();
            } catch (TransportExceptionInterface $error) {
                $this->logger->error(\sprintf('Error in WebPush::Pool.deliver: %s', $error->getMessage()));
                continue;
            }
            if (404 === $status || 410 === $status) {
                // WebPush::ExpiredSubscription
                $this->invalidate($id);
            } elseif ($status >= 400) {
                $this->logger->error(\sprintf('Error in WebPush::Pool.deliver: push service responded with %d', $status));
            }
        }

        return \count($notifications);
    }

    /**
     * The endpoint's host when it is an https URL on port 443 of a permitted push service
     * (Push::Subscription#permitted_endpoint_uri?), else null.
     */
    public static function permittedEndpointHost(?string $endpoint): ?string
    {
        if (null === $endpoint || 1 === preg_match('/[\x00-\x20\x7F-\xFF"<>\\\\^`{|}]/', $endpoint)) {
            return null;
        }
        $parts = parse_url($endpoint);
        if (false === $parts || 'https' !== strtolower($parts['scheme'] ?? '') || 443 !== ($parts['port'] ?? 443)) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        if ('' === $host) {
            return null;
        }
        foreach (self::PERMITTED_ENDPOINT_HOSTS as $permitted) {
            if ($host === $permitted || str_ends_with($host, '.'.$permitted)) {
                return $host;
            }
        }

        return null;
    }

    /**
     * WebPush::Notification#encoded_message: `JSON.generate title:, options: { body:, icon:,
     * data: { path:, badge: } }`, the badge being the user's unread rooms.
     *
     * @param array{title: string, body: string, path: string} $payload
     */
    public function message(array $payload, int $userId): string
    {
        $badge = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM memberships WHERE memberships.user_id = ? AND memberships.unread_at IS NOT NULL', [$userId]);

        return RailsJson::generate([
            'title' => $payload['title'],
            'options' => ['body' => $payload['body'], 'icon' => self::ICON_PATH, 'data' => ['path' => $payload['path'], 'badge' => $badge]],
        ]);
    }

    /** The invalid subscription handler: `Push::Subscription.find_by(id:)&.destroy`. */
    private function invalidate(int $id): void
    {
        $this->logger->info(\sprintf('Destroying push subscription: %d', $id));
        try {
            $this->connection->executeStatement('DELETE FROM push_subscriptions WHERE id = ?', [$id]);
        } catch (\Throwable $error) {
            $this->logger->error(\sprintf('Error in WebPush::Pool.invalid_subscription_handler: %s', $error->getMessage()));
        }
    }

    /** A P-256 public key (65 bytes, uncompressed) and a 16 byte auth secret, as encryption needs. */
    /** null: not decodable (logged, kept); false: decodable but unusable (invalidated); true: usable. */
    private static function usableKeys(?string $p256dh, ?string $auth): ?bool
    {
        $key = Base64::urlsafeDecode((string) $p256dh);
        $secret = Base64::urlsafeDecode((string) $auth);
        if (null === $key || null === $secret) {
            return null;
        }

        return 65 === \strlen($key) && "\x04" === $key[0] && 16 === \strlen($secret);
    }

    private function webPush(): WebPushRequests
    {
        $factory = new Psr18Client($this->httpClient);

        return new WebPushRequests(
            ['VAPID' => ['subject' => self::VAPID_SUBJECT, 'publicKey' => $this->vapidPublicKey, 'privateKey' => $this->vapidPrivateKey]],
            [],
            $factory,
            $factory,
            $factory,
        );
    }
}
