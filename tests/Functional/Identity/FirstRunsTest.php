<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

/** FirstRunsController and FirstRun (reference/app/models/first_run.rb). */
final class FirstRunsTest extends IdentityTestCase
{
    public function testFirstRunIsOnlyAvailableWithoutAnAccount(): void
    {
        $response = $this->get($this->client(), '/first_run');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('http://localhost/', $response->headers->get('Location'));
    }

    public function testFirstRunPage(): void
    {
        $this->emptyDatabase();
        $client = $this->client();
        self::assertSame('http://localhost/session/new', $this->get($client, '/')->headers->get('Location'));
        self::assertSame('http://localhost/first_run', $this->get($client, '/session/new')->headers->get('Location'));

        $response = $this->get($client, '/first_run');
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('<title>Set up Campfire</title>', $html);
        self::assertStringContainsString('<body class="signup"', $html);
        self::assertStringContainsString('<form class="center max-width" enctype="multipart/form-data" action="/first_run"', $html);
    }

    public function testCreateSetsUpTheAccountAdministratorAndFirstRoom(): void
    {
        $this->emptyDatabase();
        $client = $this->client();
        $response = $this->submit($client, 'POST', '/first_run', ['user' => ['name' => 'Ada', 'email_address' => 'ada@example.com', 'password' => 'secret123456']]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('http://localhost/', $response->headers->get('Location'));
        self::assertNotNull(self::setCookie($response, 'session_token'));

        self::assertSame('Campfire', $this->value('SELECT name FROM accounts'));
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9]{4}-[A-Za-z0-9]{4}-[A-Za-z0-9]{4}\z/', (string) $this->value('SELECT join_code FROM accounts'));
        $user = $this->row('SELECT id, name, email_address, role, status, password_digest FROM users');
        self::assertSame(['Ada', 'ada@example.com', 1, 0], [$user['name'], $user['email_address'], $user['role'], $user['status']]);
        self::assertTrue(password_verify('secret123456', (string) $user['password_digest']));
        self::assertSame(['Rooms::Open', 'All Talk', $user['id']], array_values((array) $this->row('SELECT type, name, creator_id FROM rooms')));
        self::assertSame([['user_id' => $user['id'], 'involvement' => 'mentions']], SeedHelper::rows($this->storagePath, 'SELECT user_id, involvement FROM memberships'));

        // A second run goes home.
        self::assertSame('http://localhost/', $this->submit($client, 'POST', '/first_run', ['user' => ['name' => 'Eve']])->headers->get('Location'));
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM users'));
    }

    public function testCreateRequiresUserParams(): void
    {
        $this->emptyDatabase();
        self::assertSame(400, $this->submit($this->client(), 'POST', '/first_run', [])->getStatusCode());
    }
}
