<?php

declare(strict_types=1);

namespace App\Domain\Users\Push;

use App\Database\Transactions;
use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Push::Subscription (reference/app/models/push/subscription.rb).
 */
final readonly class PushSubscriptions
{
    public const array PERMITTED_ENDPOINT_HOSTS = [
        'jmt17.google.com',
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'web.push.apple.com',
        'notify.windows.com',
    ];

    private const array COLUMNS = ['endpoint' => 'endpoint', 'p256dh_key' => 'p256dh_key', 'auth_key' => 'auth_key'];

    public function __construct(
        private EntityManagerInterface $em,
        private Connection $connection,
        private Transactions $transactions,
        private PushGateway $gateway,
        private ClockInterface $clock,
    ) {
    }

    /**
     * `user.push_subscriptions.find_by(params)` with the permitted keys present in $params.
     *
     * @param array<string, mixed> $params endpoint, p256dh_key, auth_key
     */
    public function findBy(User $user, array $params): ?PushSubscription
    {
        $sql = 'SELECT "push_subscriptions"."id" FROM "push_subscriptions" WHERE "push_subscriptions"."user_id" = ?';
        $values = [$user->getId()];
        foreach (self::COLUMNS as $key => $column) {
            if (\array_key_exists($key, $params)) {
                if (null === $params[$key]) {
                    $sql .= \sprintf(' AND "push_subscriptions"."%s" IS NULL', $column);
                } else {
                    $sql .= \sprintf(' AND "push_subscriptions"."%s" = ?', $column);
                    $values[] = \is_scalar($params[$key]) ? (string) $params[$key] : '';
                }
            }
        }
        $id = $this->connection->fetchOne($sql.' LIMIT 1', $values);

        return false === $id ? null : $this->em->find(PushSubscription::class, (int) $id);
    }

    /**
     * `@push_subscriptions.create(params.merge(user_agent:))`: the record, or null when it does
     * not pass validation.
     *
     * @param array<string, mixed> $params
     */
    public function create(User $user, array $params, ?string $userAgent): ?PushSubscription
    {
        $subscription = new PushSubscription($user);
        $subscription->setEndpoint(self::string($params['endpoint'] ?? null));
        $subscription->setP256dhKey(self::string($params['p256dh_key'] ?? null));
        $subscription->setAuthKey(self::string($params['auth_key'] ?? null));
        $subscription->setUserAgent($userAgent);
        if (!$this->isValid($subscription)) {
            return null;
        }

        return $this->transactions->transaction(function () use ($subscription): PushSubscription {
            $this->em->persist($subscription);
            $this->em->flush();

            return $subscription;
        });
    }

    /** `subscription.touch` */
    public function touch(PushSubscription $subscription): void
    {
        $this->transactions->transaction(function () use ($subscription): void {
            $subscription->touch($this->clock->now());
            $this->em->flush();
        });
    }

    /** `@push_subscriptions.destroy_by(id:)` */
    public function destroyBy(User $user, mixed $id): void
    {
        $this->transactions->transaction(function () use ($user, $id): void {
            $this->connection->executeStatement(
                'DELETE FROM "push_subscriptions" WHERE "push_subscriptions"."user_id" = ? AND "push_subscriptions"."id" = ?',
                [$user->getId(), \is_scalar($id) ? (string) $id : ''],
            );
        });
    }

    /** `Push::Subscription.destroy_by(endpoint:, user_id:)` (sign out). */
    public function destroyByEndpoint(User $user, string $endpoint): void
    {
        $this->transactions->transaction(function () use ($user, $endpoint): void {
            $this->connection->executeStatement(
                'DELETE FROM "push_subscriptions" WHERE "push_subscriptions"."endpoint" = ? AND "push_subscriptions"."user_id" = ?',
                [$endpoint, $user->getId()],
            );
        });
    }

    /** `valid?`: endpoint present, an https URL on port 443 of a permitted push service, publicly resolvable. */
    public function isValid(PushSubscription $subscription): bool
    {
        $endpoint = $subscription->getEndpoint();
        if (null === $endpoint || 1 === preg_match('/\A[[:space:]]*\z/u', $endpoint)) {
            return false;
        }

        $uri = self::parse($endpoint);

        return null !== $uri && 'https' === $uri['scheme'] && 443 === $uri['port'] && self::permittedHost($uri['host'])
            && null !== $this->gateway->resolve((string) $uri['host']);
    }

    /**
     * `subscription.notification(title:, body:, path:).deliver`: delivered only when the
     * endpoint still resolves to a public address (`resolved_endpoint_ip`).
     */
    public function deliver(PushSubscription $subscription, string $title, string $body, string $path): void
    {
        $uri = self::parse((string) $subscription->getEndpoint());
        if (null === $uri || 'https' !== $uri['scheme'] || 443 !== $uri['port'] || !self::permittedHost($uri['host'])
            || null === $this->gateway->resolve((string) $uri['host'])) {
            return;
        }

        $this->gateway->deliver($subscription, ['title' => $title, 'body' => $body, 'path' => $path]);
    }

    /**
     * `URI.parse(endpoint)` for what the validation reads: scheme, host (nil for opaque URIs) and
     * port (the scheme's default when absent). Null where Ruby raises URI::InvalidURIError.
     *
     * @return array{scheme: ?string, host: ?string, port: ?int}|null
     */
    private static function parse(string $endpoint): ?array
    {
        if (1 === preg_match('/[\x00-\x20\x7F-\xFF"<>\\\\^`{|}]/', $endpoint)) {
            return null;
        }
        $parts = parse_url($endpoint);
        if (false === $parts) {
            return null;
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : null;
        $port = $parts['port'] ?? match ($scheme) {
            'https' => 443,
            'http' => 80,
            default => null,
        };

        return ['scheme' => $scheme, 'host' => isset($parts['host']) && '' !== $parts['host'] ? $parts['host'] : null, 'port' => $port];
    }

    private static function permittedHost(?string $host): bool
    {
        if (null === $host || '' === $host) {
            return false;
        }
        $host = strtolower($host);
        foreach (self::PERMITTED_ENDPOINT_HOSTS as $permitted) {
            if ($host === $permitted || str_ends_with($host, '.'.$permitted)) {
                return true;
            }
        }

        return false;
    }

    private static function string(mixed $value): ?string
    {
        return \is_scalar($value) ? (string) $value : null;
    }
}
