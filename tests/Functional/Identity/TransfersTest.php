<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

/** Sessions::TransfersController: sign in with a transfer link (signed id, purpose :transfer, 4 hours). */
final class TransfersTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // labels.json's transfer ids were signed by Rails at the seed's clock.
        $this->setEnv('CAMPFIRE_FROZEN_TIME', (string) self::labels('clock.now'));
    }

    public function testShowRendersTheAutoSubmittingForm(): void
    {
        $id = (string) self::labels('transfers.david');
        $response = $this->get($this->client(), '/session/transfers/'.$id);

        self::assertSame(200, $response->getStatusCode());
        self::assertMatchesRegularExpression('#<form data-controller="auto-submit" action="/session/transfers/'.preg_quote($id, '#').'" accept-charset="UTF-8" method="post"><input type="hidden" name="_method" value="put" /><input type="hidden" name="authenticity_token" value="[^"]+" />#', (string) $response->getContent());
    }

    public function testUpdateSignsInTheTransferredUser(): void
    {
        $client = $this->client();
        $response = $this->submit($client, 'PUT', '/session/transfers/'.self::labels('transfers.kevin'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('http://localhost/', $response->headers->get('Location'));
        $token = self::railsCookies()->readSigned('session_token', self::cookieValue($response, 'session_token'));
        self::assertSame(self::id('users.kevin'), (int) $this->value('SELECT user_id FROM sessions WHERE token = ?', [$token]));
    }

    public function testExpiredOrForgedTransfersAreBadRequests(): void
    {
        foreach ([(string) self::labels('transfers.david_expired'), 'forged--0000', (string) self::labels('avatar_tokens.david')] as $id) {
            self::ensureKernelShutdown();
            $response = $this->submit($this->client(), 'PUT', '/session/transfers/'.$id);
            self::assertSame(400, $response->getStatusCode(), $id);
            self::assertSame('', $response->getContent());
        }
    }

    public function testTransferIdsVerifyAsRailsGeneratesThem(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $html = (string) $this->get($client, '/users/me/profile')->getContent();

        self::assertSame(1, preg_match('#value="http://localhost/session/transfers/([^"]+)" id="session_transfer_url"#', $html, $m));
        self::ensureKernelShutdown();
        $response = $this->submit($this->client(), 'PATCH', '/session/transfers/'.$m[1]);
        self::assertSame(302, $response->getStatusCode());
    }
}
