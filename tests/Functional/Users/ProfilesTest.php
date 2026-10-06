<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users;

use App\Tests\Functional\Identity\IdentityTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Users::ProfilesController: always the signed-in user. */
final class ProfilesTest extends IdentityTestCase
{
    public function testShow(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $response = $this->get($client, '/users/me/profile');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('<title>David</title>', $html);
        self::assertStringContainsString('<section class="panel flex flex-column gap" style="view-transition-name: avatar-'.self::id('users.david').'">', $html);
        self::assertStringContainsString('value="David" name="user[name]" id="user_name"', $html);
        self::assertStringContainsString('<form data-controller="sessions" action="/session" accept-charset="UTF-8" method="post"><input type="hidden" name="_method" value="delete" />', $html);
        // Shared rooms by name, then the direct rooms.
        self::assertSame(1, preg_match_all('#<strong>All Pets</strong>.*<strong>All Talk</strong>.*<strong>HQ</strong>.*<hr class="separator full-width" style="--border-style: solid">.*<strong>Jason</strong>#s', $html));
        self::assertStringContainsString('action="/rooms/'.self::id('rooms.hq').'/involvement?involvement=invisible"', $html);
        // The Chrome install instructions are hidden; the transfer link is the user's own.
        self::assertStringNotContainsString('pwa__instructions', $html);
        self::assertStringContainsString('Use this link to login automatically on another device', $html);
    }

    public function testSafariSeesInstallInstructions(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $html = (string) $this->get($client, '/users/me/profile', ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15'])->getContent();

        self::assertStringContainsString('<li>Click <em>Add to Dock…</em>.</li>', $html);
    }

    public function testUpdate(): void
    {
        $client = $this->client();
        $this->signIn($client, 'kevin');
        $response = $this->submit($client, 'PATCH', '/users/me/profile', ['user' => ['name' => 'Kev', 'bio' => 'Hi', 'password' => 'newpassword1', 'role' => 'administrator']]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('http://localhost/users/me/profile', $response->headers->get('Location'));
        $row = $this->row('SELECT name, bio, role, email_address, password_digest FROM users WHERE id = ?', [self::id('users.kevin')]);
        self::assertSame(['Kev', 'Hi', 0, 'kevin@37signals.com'], [$row['name'], $row['bio'], $row['role'], $row['email_address']]);
        self::assertTrue(password_verify('newpassword1', (string) $row['password_digest']));

        $html = (string) $this->get($client, '/users/me/profile')->getContent();
        self::assertStringContainsString('<span class="for-screen-reader" role="alert" aria-atomic="true">✓</span>', $html);
    }

    public function testBlankPasswordKeepsTheCurrentOne(): void
    {
        $client = $this->client();
        $this->signIn($client, 'kevin');
        $before = $this->value('SELECT password_digest FROM users WHERE id = ?', [self::id('users.kevin')]);
        $this->submit($client, 'PATCH', '/users/me/profile', ['user' => ['password' => '']]);

        self::assertSame($before, $this->value('SELECT password_digest FROM users WHERE id = ?', [self::id('users.kevin')]));
    }

    public function testAvatarUploadNotice(): void
    {
        $client = $this->client();
        $this->signIn($client, 'kevin');
        $png = sys_get_temp_dir().'/campfire-avatar-'.bin2hex(random_bytes(4)).'.png';
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true));
        $response = $this->submit($client, 'PATCH', '/users/me/profile', [], ['user' => ['avatar' => new UploadedFile($png, 'me.png', 'image/png', null, true)]]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM active_storage_attachments WHERE record_type = 'User' AND record_id = ? AND name = 'avatar'", [self::id('users.kevin')]));
        $html = (string) $this->get($client, '/users/me/profile')->getContent();
        self::assertStringContainsString('It may take up to 30 minutes to change everywhere.', $html);
        self::assertStringContainsString('<span class="for-screen-reader">Delete avatar</span>', $html);
    }

    public function testUpdateRequiresUserParams(): void
    {
        $client = $this->client();
        $this->signIn($client, 'kevin');

        self::assertSame(400, $this->submit($client, 'PATCH', '/users/me/profile', [])->getStatusCode());
    }
}
