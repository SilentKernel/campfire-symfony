<?php

declare(strict_types=1);

namespace App\Tests\Functional\Accounts;

use App\Tests\Functional\Identity\IdentityTestCase;
use App\Tests\Functional\Identity\SeedHelper;

/** AccountsController, Accounts::JoinCodesController and Accounts::CustomStylesController. */
final class AccountsTest extends IdentityTestCase
{
    public function testEditForAnAdministrator(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $response = $this->get($client, '/account/edit');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        $account = self::id('accounts.signal');
        self::assertStringContainsString('<title>Account settings</title>', $html);
        self::assertStringContainsString('action="/account.'.$account.'" accept-charset="UTF-8" method="post"><input type="hidden" name="_method" value="patch" />', $html);
        self::assertStringContainsString('<input value="true" type="hidden" name="account[settings][restrict_room_creation_to_administrators]"', $html);
        self::assertStringContainsString('value="http://localhost/join/CRMu-l8Ge-KB9B"', $html);
        self::assertStringContainsString('<span class="for-screen-reader">Regenerate join link</span>', $html);
        self::assertStringContainsString('href="/rooms/'.self::id('rooms.pets').'"><img aria-hidden="true" src="/assets/arrow-left', $html);
        // Administrators first; banned people listed for administrators; no bots.
        self::assertSame(1, preg_match('#<strong>David</strong>.*<strong>Jason</strong>.*<hr class="separator full-width".*<strong>JZ</strong>.*<strong>Mallory Banned</strong>#s', $html));
        self::assertStringNotContainsString('<strong>Bender Bot</strong>', $html);
        self::assertStringNotContainsString('<strong>Rita Lopez</strong>', $html);
        self::assertStringContainsString('<input name="user[role]" disabled="disabled" type="hidden" value="member" /><input data-action="form#submit" hidden="hidden" id="role_user_'.self::id('users.david').'" disabled="disabled" type="checkbox" value="administrator" checked="checked" name="user[role]" />', $html);
        self::assertStringNotContainsString('next_page_container', $html);
    }

    public function testEditForAMember(): void
    {
        $client = $this->client();
        $this->signIn($client, 'kevin');
        $html = (string) $this->get($client, '/account/edit')->getContent();

        self::assertStringContainsString('<h1 class="flex-item-grow txt-x-large">37signals</h1>', $html);
        self::assertStringNotContainsString('account[name]', $html);
        self::assertStringNotContainsString('Regenerate join link', $html);
        self::assertStringNotContainsString('Mallory Banned', $html);
        self::assertStringNotContainsString('/account/bots', $html);
    }

    public function testUpdate(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $response = $this->submit($client, 'PATCH', '/account.'.self::id('accounts.signal'), ['account' => ['name' => 'Signal', 'join_code' => 'nope']]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('http://localhost/account/edit', $response->headers->get('Location'));
        self::assertSame(['Signal', 'CRMu-l8Ge-KB9B'], array_values((array) $this->row('SELECT name, join_code FROM accounts')));
        self::assertStringContainsString('role="alert" aria-atomic="true">✓</span>', (string) $this->get($client, '/account/edit')->getContent());
    }

    public function testUpdateSettings(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $this->submit($client, 'PUT', '/account', ['account' => ['settings' => ['restrict_room_creation_to_administrators' => 'true']]]);

        self::assertSame(['restrict_room_creation_to_administrators' => true], json_decode((string) $this->value('SELECT settings FROM accounts'), true));
        $html = (string) $this->get($client, '/account/edit')->getContent();
        self::assertStringContainsString('<input value="false" type="hidden"', $html);
    }

    public function testMembersCannotUpdate(): void
    {
        $client = $this->client();
        $this->signIn($client, 'kevin');
        $response = $this->submit($client, 'PATCH', '/account', ['account' => ['name' => 'Mine']]);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('37signals', $this->value('SELECT name FROM accounts'));
    }

    public function testUpdateWithoutCsrfIs422(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');

        self::assertSame(422, $this->submit($client, 'PATCH', '/account', ['account' => ['name' => 'X'], 'authenticity_token' => 'bad'])->getStatusCode());
    }

    public function testRegenerateJoinCode(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $response = $this->submit($client, 'POST', '/account/join_code');

        self::assertSame('http://localhost/account/edit', $response->headers->get('Location'));
        $code = (string) $this->value('SELECT join_code FROM accounts');
        self::assertNotSame('CRMu-l8Ge-KB9B', $code);
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9]{4}-[A-Za-z0-9]{4}-[A-Za-z0-9]{4}\z/', $code);

        self::ensureKernelShutdown();
        $member = $this->client();
        $this->signIn($member, 'kevin');
        self::assertSame(403, $this->submit($member, 'POST', '/account/join_code')->getStatusCode());
    }

    public function testCustomStyles(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        self::assertStringContainsString('<title>Custom styles</title>', (string) $this->get($client, '/account/custom_styles/edit')->getContent());

        $response = $this->submit($client, 'PATCH', '/account/custom_styles', ['account' => ['custom_styles' => 'body { color: red }']]);
        self::assertSame('http://localhost/account/custom_styles/edit', $response->headers->get('Location'));
        self::assertSame('body { color: red }', $this->value('SELECT custom_styles FROM accounts'));
        $html = (string) $this->get($client, '/account/custom_styles/edit')->getContent();
        self::assertStringContainsString('<style data-turbo-track="reload">body { color: red }</style>', $html);
        self::assertStringContainsString(">\nbody { color: red }</textarea>", $html);
    }

    public function testCustomStylesAreForAdministrators(): void
    {
        $client = $this->client();
        $this->signIn($client, 'kevin');

        self::assertSame(403, $this->get($client, '/account/custom_styles/edit')->getStatusCode());
        self::assertSame(403, $this->submit($client, 'PATCH', '/account/custom_styles', ['account' => ['custom_styles' => 'x']])->getStatusCode());
    }

    public function testUnknownActionsAre404(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');

        self::assertSame(404, $this->get($client, '/account')->getStatusCode());
        self::assertSame(404, $this->get($client, '/account/new')->getStatusCode());
    }

    public function testEditListsEveryoneAndPagesAfter500(): void
    {
        $connection = \App\Tests\Support\SeedDatabase::connect($this->storagePath);
        for ($i = 0; $i < 500; ++$i) {
            $connection->insert('users', ['name' => \sprintf('Zed %03d', $i), 'role' => 0, 'status' => 0, 'created_at' => '2026-03-02 16:00:00', 'updated_at' => '2026-03-02 16:00:00']);
        }
        $client = $this->client();
        $this->signIn($client, 'david');
        $html = (string) $this->get($client, '/account/edit')->getContent();

        self::assertStringContainsString('<strong>Zed 499</strong>', $html);
        self::assertStringContainsString('<turbo-frame loading="lazy" class="flex center" id="next_page_container" src="/account/users.turbo_stream?page=2">', $html);
        self::assertCount(505, SeedHelper::rows($this->storagePath, 'SELECT id FROM users WHERE status = 0 AND role != 2'));
    }
}
