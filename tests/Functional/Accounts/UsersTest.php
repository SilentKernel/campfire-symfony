<?php

declare(strict_types=1);

namespace App\Tests\Functional\Accounts;

use App\Tests\Functional\Identity\IdentityTestCase;

/** Accounts::UsersController: the people list's pages, roles and removal (deactivation). */
final class UsersTest extends IdentityTestCase
{
    public function testIndexIsATurboStreamPage(): void
    {
        $client = $this->client();
        $this->signIn($client, 'kevin');
        $response = $this->get($client, '/account/users.turbo_stream?page=1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/vnd.turbo-stream.html; charset=utf-8', $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        self::assertStringStartsWith('<turbo-stream action="replace" target="next_page_container"><template><li class="flex align-center gap margin-none ">', $body);
        self::assertStringContainsString('<strong>Lonely Lou</strong>', $body);
        self::assertStringNotContainsString('Mallory Banned', $body);
        self::assertStringNotContainsString('target="account_users"', $body);
    }

    public function testIndexPastTheLastPageKeepsPaging(): void
    {
        $client = $this->client();
        $this->signIn($client, 'kevin');
        $body = (string) $this->get($client, '/account/users?page=2', ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html'])->getContent();

        self::assertSame("<turbo-stream action=\"replace\" target=\"next_page_container\"><template></template></turbo-stream>\n\n  <turbo-stream action=\"append\" target=\"account_users\"><template><turbo-frame loading=\"lazy\" class=\"flex center\" id=\"next_page_container\" src=\"/account/users.turbo_stream?page=3\">\n  <div class=\"spinner center\"></div>\n</turbo-frame></template></turbo-stream>\n", $body);
    }

    public function testIndexHasNoHtml(): void
    {
        $client = $this->client();
        $this->signIn($client, 'kevin');

        self::assertSame(406, $this->get($client, '/account/users', ['HTTP_ACCEPT' => 'text/html'])->getStatusCode());
    }

    public function testUpdateRole(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $kevin = self::id('users.kevin');

        $response = $this->submit($client, 'PATCH', "/account/users/{$kevin}", ['user' => ['role' => 'administrator']]);
        self::assertSame('http://localhost/account/edit', $response->headers->get('Location'));
        self::assertSame(1, (int) $this->value('SELECT role FROM users WHERE id = ?', [$kevin]));

        // Anything but "administrator" (bot included) is "member".
        $this->submit($client, 'PATCH', "/account/users/{$kevin}", ['user' => ['role' => 'bot']]);
        self::assertSame(0, (int) $this->value('SELECT role FROM users WHERE id = ?', [$kevin]));
    }

    public function testUpdateRoleIsForAdministratorsAndActiveUsers(): void
    {
        $client = $this->client();
        $this->signIn($client, 'kevin');
        self::assertSame(403, $this->submit($client, 'PATCH', '/account/users/'.self::id('users.jz'), ['user' => ['role' => 'administrator']])->getStatusCode());

        self::ensureKernelShutdown();
        $admin = $this->client();
        $this->signIn($admin, 'david');
        self::assertSame(404, $this->submit($admin, 'PATCH', '/account/users/'.self::id('users.rita'), ['user' => ['role' => 'administrator']])->getStatusCode());
        self::assertSame(400, $this->submit($admin, 'PATCH', '/account/users/'.self::id('users.jz'), [])->getStatusCode());
    }

    public function testDestroyDeactivates(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $jz = self::id('users.jz');
        $response = $this->submit($client, 'DELETE', "/account/users/{$jz}");

        self::assertSame('http://localhost/account/edit', $response->headers->get('Location'));
        $row = $this->row('SELECT status, email_address FROM users WHERE id = ?', [$jz]);
        self::assertSame(1, $row['status']);
        self::assertMatchesRegularExpression('/\Ajz-deactivated-[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}@37signals\.com\z/', $row['email_address']);
        // Shared room memberships, push subscriptions and sessions go; direct rooms stay.
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM memberships INNER JOIN rooms ON rooms.id = memberships.room_id WHERE user_id = ? AND rooms.type != 'Rooms::Direct'", [$jz]));
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM memberships INNER JOIN rooms ON rooms.id = memberships.room_id WHERE user_id = ? AND rooms.type = 'Rooms::Direct'", [$jz]));
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$jz]));
    }
}
