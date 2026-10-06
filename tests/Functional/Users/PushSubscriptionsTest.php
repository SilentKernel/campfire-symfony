<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users;

use App\Domain\Users\Push\PushGateway;
use App\Domain\Users\Push\RecordingPushGateway;
use App\Tests\Functional\Identity\IdentityTestCase;

/** Users::PushSubscriptionsController, TestNotificationsController and Push::Subscription. */
final class PushSubscriptionsTest extends IdentityTestCase
{
    public function testIndex(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $html = (string) $this->get($client, '/users/me/push_subscriptions')->getContent();

        self::assertStringContainsString('<strong>Chrome 113.0.0.0 on Macintosh</strong><br>', $html);
        self::assertStringContainsString('<strong>Firefox 124.0 on Macintosh</strong><br>', $html);
        self::assertStringNotContainsString('/789<', $html);
        self::assertStringContainsString('action="/users/me/push_subscriptions/'.self::id('push_subscriptions.david_chrome').'/test_notifications"', $html);
    }

    public function testCreate(): void
    {
        $client = $this->client();
        $this->signIn($client, 'kevin');
        $response = $this->submit($client, 'POST', '/users/me/push_subscriptions', ['push_subscription' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/new', 'p256dh_key' => 'p', 'auth_key' => 'a']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        $row = $this->row("SELECT user_id, p256dh_key, auth_key, user_agent FROM push_subscriptions WHERE endpoint = 'https://fcm.googleapis.com/fcm/send/new'");
        self::assertSame([self::id('users.kevin'), 'p', 'a', self::RAILS_CHROME], array_values((array) $row));
    }

    public function testCreateTouchesAnExistingSubscription(): void
    {
        $client = $this->client();
        $this->signIn($client, 'kevin');
        $id = self::id('push_subscriptions.kevin_chrome');
        $before = $this->value('SELECT updated_at FROM push_subscriptions WHERE id = ?', [$id]);
        $response = $this->submit($client, 'POST', '/users/me/push_subscriptions', ['push_subscription' => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/789']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame($before, $this->value('SELECT updated_at FROM push_subscriptions WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [self::id('users.kevin')]));
    }

    public function testInvalidEndpointsAreUnprocessable(): void
    {
        foreach (['http://fcm.googleapis.com/x', 'https://fcm.googleapis.com:8443/x', 'https://evil.example.com/x', 'not a url', 'https://fcm.googleapis.com.evil.com/x', ''] as $endpoint) {
            self::ensureKernelShutdown();
            $client = $this->client();
            $this->signIn($client, 'kevin');
            $response = $this->submit($client, 'POST', '/users/me/push_subscriptions', ['push_subscription' => ['endpoint' => $endpoint, 'p256dh_key' => 'p']]);
            self::assertSame(422, $response->getStatusCode(), $endpoint);
        }
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [self::id('users.kevin')]));
    }

    public function testDestroyOnlyTouchesTheUsersSubscriptions(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $response = $this->submit($client, 'DELETE', '/users/me/push_subscriptions/'.self::id('push_subscriptions.david_chrome'));
        $this->submit($client, 'DELETE', '/users/me/push_subscriptions/'.self::id('push_subscriptions.kevin_chrome'));

        self::assertSame('http://localhost/users/me/push_subscriptions', $response->headers->get('Location'));
        self::assertFalse($this->value('SELECT 1 FROM push_subscriptions WHERE id = ?', [self::id('push_subscriptions.david_chrome')]));
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM push_subscriptions WHERE id = ?', [self::id('push_subscriptions.kevin_chrome')]));
    }

    public function testTestNotification(): void
    {
        $client = $this->client();
        $client->disableReboot();
        $this->signIn($client, 'david');
        $id = self::id('push_subscriptions.david_chrome');
        $response = $this->submit($client, 'POST', "/users/me/push_subscriptions/{$id}/test_notifications");

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('http://localhost/users/me/push_subscriptions', $response->headers->get('Location'));
        $gateway = static::getContainer()->get(PushGateway::class);
        \assert($gateway instanceof RecordingPushGateway);
        self::assertCount(1, $gateway->deliveries);
        self::assertSame($id, $gateway->deliveries[0]['subscription_id']);
        $payload = $gateway->deliveries[0]['payload'];
        self::assertSame('Campfire Test', $payload['title']);
        self::assertSame('http://localhost/users/me/push_subscriptions', $payload['path']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $payload['body']);
    }

    public function testTestNotificationForAnotherUsersSubscriptionIsNotFound(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');

        self::assertSame(404, $this->submit($client, 'POST', '/users/me/push_subscriptions/'.self::id('push_subscriptions.kevin_chrome').'/test_notifications')->getStatusCode());
    }
}
