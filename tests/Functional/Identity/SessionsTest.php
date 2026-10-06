<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Cable\Broadcaster;
use App\Cable\RecordingBroadcaster;
use App\Domain\Users\SignInRateLimiter;

/** SessionsController (reference/app/controllers/sessions_controller.rb). */
final class SessionsTest extends IdentityTestCase
{
    public function testSignInPage(): void
    {
        $client = $this->client();
        $response = $this->get($client, '/session/new');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        $html = (string) $response->getContent();
        self::assertStringContainsString('<title>Sign in</title>', $html);
        self::assertStringContainsString('<meta name="csrf-token" content="', $html);
        self::assertStringContainsString('<meta name="turbo-visit-control" content="reload">', $html);
        self::assertStringContainsString('<form class="flex flex-column gap" action="http://localhost/session" accept-charset="UTF-8" method="post">', $html);
        self::assertStringContainsString('<legend class="txt-large txt-align-center"><strong>37signals</strong></legend>', $html);
        self::assertStringContainsString('title="Email David" href="mailto:&quot;David&quot; &lt;david@37signals.com&gt;"', $html);
        self::assertStringNotContainsString('shake', $html);
    }

    public function testSignInPageEchoesTheEmailAddressParam(): void
    {
        $html = (string) $this->get($this->client(), '/session/new?email_address=a%27b%40example.com')->getContent();

        self::assertStringContainsString('placeholder="Enter your email address" value="a&#39;b@example.com" type="email"', $html);
    }

    public function testSignInPageRedirectsToFirstRunWithoutUsers(): void
    {
        $this->emptyDatabase();
        $response = $this->get($this->client(), '/session/new');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('http://localhost/first_run', $response->headers->get('Location'));
    }

    public function testSignInStartsASessionAndRedirects(): void
    {
        $client = $this->client();
        $response = $this->submit($client, 'POST', '/session', ['email_address' => self::labels('emails.david'), 'password' => self::labels('passwords.all')], server: ['REMOTE_ADDR' => '198.51.100.7']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('http://localhost/', $response->headers->get('Location'));
        $token = self::railsCookies()->readSigned('session_token', self::cookieValue($response, 'session_token'));
        self::assertIsString($token);
        $row = $this->row('SELECT user_id, user_agent, ip_address FROM sessions WHERE token = ?', [$token]);
        self::assertSame(['user_id' => self::id('users.david'), 'user_agent' => self::RAILS_CHROME, 'ip_address' => '198.51.100.7'], $row);
    }

    public function testSignInReturnsToTheRequestedUrl(): void
    {
        $client = $this->client();
        $this->get($client, '/account/edit');
        self::assertSame('http://localhost/session/new', $client->getResponse()->headers->get('Location'));

        $response = $this->submit($client, 'POST', '/session', ['email_address' => self::labels('emails.kevin'), 'password' => self::labels('passwords.all')]);
        self::assertSame('http://localhost/account/edit', $response->headers->get('Location'));
    }

    public function testWrongPasswordRendersTheFormAgainWithStatus401(): void
    {
        $client = $this->client();
        $response = $this->submit($client, 'POST', '/session', ['email_address' => self::labels('emails.david'), 'password' => 'wrong']);

        self::assertSame(401, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('<div class="panel shake">', $html);
        self::assertStringContainsString('<span class="for-screen-reader" role="alert" aria-atomic="true">Too many requests or unauthorized.</span>', $html);
        self::assertStringContainsString('value="david@37signals.com"', $html);
        self::assertNull(self::setCookie($response, 'session_token'));
    }

    public function testUnknownBlankAndInactiveUsersAreRejected(): void
    {
        foreach ([
            ['email_address' => 'nobody@example.com', 'password' => 'secret123456'],
            ['email_address' => self::labels('emails.david'), 'password' => ''],
            ['email_address' => self::labels('emails.david')],
            ['email_address' => self::labels('emails.rita'), 'password' => self::labels('passwords.all')],
            ['email_address' => self::labels('emails.mallory'), 'password' => self::labels('passwords.all')],
        ] as $params) {
            self::ensureKernelShutdown();
            $response = $this->submit($this->client(), 'POST', '/session', $params);
            self::assertSame(401, $response->getStatusCode(), json_encode($params, \JSON_THROW_ON_ERROR));
        }
    }

    public function testSignInRequiresTheAuthenticityToken(): void
    {
        $response = $this->submit($this->client(), 'POST', '/session', ['email_address' => self::labels('emails.david'), 'password' => self::labels('passwords.all'), 'authenticity_token' => 'forged']);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testSignInIsRateLimitedTo10AttemptsPer3MinutesPerIp(): void
    {
        $client = $this->client();
        $limiter = static::getContainer()->get(SignInRateLimiter::class);
        \assert($limiter instanceof SignInRateLimiter);
        for ($i = 0; $i < SignInRateLimiter::LIMIT; ++$i) {
            self::assertTrue($limiter->attempt('127.0.0.1'));
        }

        $client->disableReboot();
        $response = $this->submit($client, 'POST', '/session', ['email_address' => self::labels('emails.david'), 'password' => self::labels('passwords.all')]);

        self::assertSame(429, $response->getStatusCode());
        self::assertStringContainsString('Too many requests or unauthorized.', (string) $response->getContent());
        self::assertNull(self::setCookie($response, 'session_token'));
        // Another address has its own window.
        self::assertTrue($limiter->attempt('203.0.113.1'));
    }

    public function testSignOutEndsTheSession(): void
    {
        $client = $this->client();
        $token = $this->signIn($client, 'david');
        $response = $this->submit($client, 'DELETE', '/session', ['push_subscription_endpoint' => 'https://fcm.googleapis.com/fcm/send/123']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('http://localhost/', $response->headers->get('Location'));
        self::assertFalse($this->value('SELECT 1 FROM sessions WHERE token = ?', [$token]));
        self::assertFalse($this->value('SELECT 1 FROM push_subscriptions WHERE id = ?', [self::id('push_subscriptions.david_chrome')]));
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM push_subscriptions WHERE id = ?', [self::id('push_subscriptions.david_firefox')]));
        self::assertStringStartsWith('session_token=; path=/; max-age=0; expires=Thu, 01 Jan 1970 00:00:00', (string) self::setCookie($response, 'session_token'));

        $broadcaster = static::getContainer()->get(Broadcaster::class);
        \assert($broadcaster instanceof RecordingBroadcaster);
        self::assertContains([self::id('users.david'), true], array_map(static fn (array $d): array => array_values($d), $broadcaster->disconnects));
    }

    public function testOutdatedBrowsersGetTheIncompatibleBrowserPage(): void
    {
        $client = $this->client();
        $response = $this->get($client, '/session/new', ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.0.0 Safari/537.36']);

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('<title>Unsupported browser</title>', $html);
        self::assertStringContainsString('Upgrade to a supported web browser', $html);
        self::assertStringContainsString('<strong>Opera</strong>', $html);
        self::assertStringContainsString('<span> 17.2+</span>', $html);
        self::assertStringNotContainsString('<strong>Ie</strong>', $html);

        // Apple Messages link previews get a neutral title.
        $html = (string) $this->get($client, '/session/new', ['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; MSIE 9.0) facebookexternalhit/1.1 Facebot Twitterbot/1.0'])->getContent();
        self::assertStringContainsString('<title>Campfire</title>', $html);
    }

    public function testUnknownFormatIs406(): void
    {
        self::assertSame(406, $this->get($this->client(), '/session/new.json')->getStatusCode());
    }
}
