<?php

declare(strict_types=1);

namespace App\Tests\Functional\Accounts;

use App\Tests\Functional\Identity\IdentityTestCase;
use App\Tests\Functional\Identity\SeedHelper;

/** Accounts::BotsController and Accounts::Bots::KeysController. */
final class BotsTest extends IdentityTestCase
{
    public function testIndex(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $html = (string) $this->get($client, '/account/bots')->getContent();

        self::assertStringContainsString('<title>Chat bots</title>', $html);
        self::assertStringContainsString('value="curl -d &#39;Hello!&#39; http://localhost/rooms/'.self::id('rooms.watercooler').'/'.self::labels('bot_keys.bender').'/messages"', $html);
        self::assertStringContainsString('value="curl -F &quot;attachment=@/path/to/file&quot; http://localhost/rooms/', $html);
        self::assertStringNotContainsString('Old Bot', $html);
    }

    public function testBotsAreForAdministrators(): void
    {
        $client = $this->client();
        $this->signIn($client, 'kevin');

        foreach (['/account/bots', '/account/bots/new', '/account/bots/'.self::id('users.bender').'/edit'] as $path) {
            self::assertSame(403, $this->get($client, $path)->getStatusCode(), $path);
        }
        self::assertSame(403, $this->submit($client, 'POST', '/account/bots', ['user' => ['name' => 'X']])->getStatusCode());
        self::assertSame(403, $this->submit($client, 'PUT', '/account/bots/'.self::id('users.bender').'/key')->getStatusCode());
    }

    public function testCreate(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $response = $this->submit($client, 'POST', '/account/bots', ['user' => ['name' => 'Hal', 'webhook_url' => 'https://example.com/hal']]);

        self::assertSame('http://localhost/account/bots', $response->headers->get('Location'));
        $bot = $this->row("SELECT id, role, status, bot_token, email_address FROM users WHERE name = 'Hal'");
        self::assertSame([2, 0, null], [$bot['role'], $bot['status'], $bot['email_address']]);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9]{12}\z/', $bot['bot_token']);
        self::assertSame('https://example.com/hal', $this->value('SELECT url FROM webhooks WHERE user_id = ?', [$bot['id']]));
        self::assertNotEmpty(SeedHelper::rows($this->storagePath, 'SELECT id FROM memberships WHERE user_id = ?', [$bot['id']]));
    }

    public function testCreateWithoutWebhook(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $this->submit($client, 'POST', '/account/bots', ['user' => ['name' => 'Quiet']]);

        $id = $this->value("SELECT id FROM users WHERE name = 'Quiet'");
        self::assertFalse($this->value('SELECT 1 FROM webhooks WHERE user_id = ?', [$id]));
    }

    public function testEditAndUpdate(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $bender = self::id('users.bender');
        $html = (string) $this->get($client, "/account/bots/{$bender}/edit")->getContent();
        self::assertStringContainsString('value="http://example.com/bender" name="user[webhook_url]"', $html);
        self::assertStringContainsString('value="Bender Bot" name="user[name]"', $html);

        $this->submit($client, 'PATCH', "/account/bots/{$bender}", ['user' => ['name' => 'Bender', 'webhook_url' => 'https://example.com/b2']]);
        self::assertSame('Bender', $this->value('SELECT name FROM users WHERE id = ?', [$bender]));
        self::assertSame('https://example.com/b2', $this->value('SELECT url FROM webhooks WHERE user_id = ?', [$bender]));

        // A blank webhook URL removes the webhook.
        $this->submit($client, 'PATCH', "/account/bots/{$bender}", ['user' => ['name' => 'Bender', 'webhook_url' => '']]);
        self::assertFalse($this->value('SELECT 1 FROM webhooks WHERE user_id = ?', [$bender]));
    }

    public function testEditShowsTheAttachedAvatar(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $html = (string) $this->get($client, '/account/bots/'.self::id('users.deploy_bot').'/edit')->getContent();

        self::assertMatchesRegularExpression('#<img alt="Bot avatar" data-upload-preview-target="image" src="http://localhost/rails/active_storage/blobs/redirect/[^/]+/[^"]+" width="48" height="48" />#', $html);
    }

    public function testDestroyDeactivatesTheBot(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $bender = self::id('users.bender');
        $response = $this->submit($client, 'DELETE', "/account/bots/{$bender}");

        self::assertSame('http://localhost/account/bots', $response->headers->get('Location'));
        self::assertSame(1, (int) $this->value('SELECT status FROM users WHERE id = ?', [$bender]));
        self::assertSame(404, $this->get($client, "/account/bots/{$bender}/edit")->getStatusCode());
    }

    public function testResetKey(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $bender = self::id('users.bender');
        $response = $this->submit($client, 'PUT', "/account/bots/{$bender}/key");

        self::assertSame('http://localhost/account/bots', $response->headers->get('Location'));
        $token = (string) $this->value('SELECT bot_token FROM users WHERE id = ?', [$bender]);
        self::assertNotSame('BenderBot123', $token);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9]{12}\z/', $token);
        self::assertSame(404, $this->submit($client, 'PUT', '/account/bots/'.self::id('users.kevin').'/key')->getStatusCode());
    }
}
