<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users;

use App\Tests\Functional\Identity\IdentityTestCase;
use App\Tests\Functional\Identity\SeedHelper;

/** UsersController: joining with the account's join code, and users#show. */
final class UsersTest extends IdentityTestCase
{
    public function testJoinPage(): void
    {
        $response = $this->get($this->client(), '/join/'.self::labels('join_codes.signal'));

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('<title>Sign up</title>', $html);
        self::assertStringContainsString('<form class="center" enctype="multipart/form-data" action="/join/CRMu-l8Ge-KB9B" accept-charset="UTF-8" method="post">', $html);
        self::assertStringContainsString('<a class="btn flex-item-justify-end" href="/session/new">', $html);
    }

    public function testWrongJoinCodeIsNotFound(): void
    {
        $client = $this->client();
        $response = $this->get($client, '/join/nope');
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('', $response->getContent());

        self::assertSame(404, $this->submit($client, 'POST', '/join/nope', ['user' => ['name' => 'Eve']])->getStatusCode());
    }

    public function testSignedInUsersAreSentHome(): void
    {
        $client = $this->client();
        $this->signIn($client, 'kevin');

        self::assertSame('http://localhost/', $this->get($client, '/join/'.self::labels('join_codes.signal'))->headers->get('Location'));
    }

    public function testJoiningCreatesAMemberOfEveryOpenRoomAndSignsIn(): void
    {
        $client = $this->client();
        $response = $this->submit($client, 'POST', '/join/'.self::labels('join_codes.signal'), ['user' => ['name' => 'Newbie', 'email_address' => 'new@example.com', 'password' => 'secret123456', 'role' => 'administrator']]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('http://localhost/', $response->headers->get('Location'));
        self::assertNotNull(self::setCookie($response, 'session_token'));

        $user = $this->row("SELECT id, role, status FROM users WHERE email_address = 'new@example.com'");
        self::assertSame([0, 0], [$user['role'], $user['status']], 'role is not permitted');
        $rooms = array_column(SeedHelper::rows($this->storagePath, 'SELECT room_id FROM memberships WHERE user_id = ? ORDER BY room_id', [$user['id']]), 'room_id');
        $open = array_column(SeedHelper::rows($this->storagePath, "SELECT id FROM rooms WHERE type = 'Rooms::Open' ORDER BY id"), 'id');
        self::assertSame($open, $rooms);
    }

    public function testJoiningWithATakenEmailAddressGoesToSignIn(): void
    {
        $response = $this->submit($this->client(), 'POST', '/join/'.self::labels('join_codes.signal'), ['user' => ['name' => 'Copy', 'email_address' => 'kevin@37signals.com', 'password' => 'x']]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('http://localhost/session/new?email_address=kevin%4037signals.com', $response->headers->get('Location'));
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM users WHERE email_address = 'kevin@37signals.com'"));
    }

    public function testShowForAnAdministrator(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $html = (string) $this->get($client, '/users/'.self::id('users.jason'))->getContent();

        self::assertStringContainsString('<title>Jason</title>', $html);
        self::assertStringContainsString('<div><a href="mailto:jason@37signals.com">jason@37signals.com</a></div>', $html);
        self::assertStringContainsString('action="/rooms/directs?user_ids%5B%5D='.self::id('users.jason').'"', $html);
        self::assertStringContainsString('Share to get them back into their account', $html);
        self::assertStringContainsString('<span>Ban Jason</span>', $html);
    }

    public function testShowForAMember(): void
    {
        $client = $this->client();
        $this->signIn($client, 'kevin');
        $html = (string) $this->get($client, '/users/'.self::id('users.jason'))->getContent();

        self::assertStringNotContainsString('mailto:', $html);
        self::assertStringNotContainsString('session_transfer_url', $html);
        self::assertStringNotContainsString('/ban"', $html);
    }

    public function testShowDeactivatedBannedAndBots(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');

        self::assertStringContainsString('<div>Rita Lopez is no longer on this account</div>', (string) $this->get($client, '/users/'.self::id('users.rita'))->getContent());
        $mallory = (string) $this->get($client, '/users/'.self::id('users.mallory'))->getContent();
        self::assertStringContainsString('<div class="flex flex-column gap banned">', $mallory);
        self::assertStringContainsString('<span>Remove ban</span>', $mallory);
        self::assertStringContainsString('<div>Old Bot is no longer on this account</div>', (string) $this->get($client, '/users/'.self::id('users.old_bot'))->getContent());
        self::assertStringContainsString('class="btn btn--primary full-width txt--large"', (string) $this->get($client, '/users/'.self::id('users.bender'))->getContent());
    }

    public function testUnknownUserIsNotFound(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');

        self::assertSame(404, $this->get($client, '/users/1')->getStatusCode());
        self::assertSame(404, $this->get($client, '/users/abc')->getStatusCode());
    }

    public function testShowRequiresSignIn(): void
    {
        self::assertSame('http://localhost/session/new', $this->get($this->client(), '/users/'.self::id('users.jason'))->headers->get('Location'));
    }
}
