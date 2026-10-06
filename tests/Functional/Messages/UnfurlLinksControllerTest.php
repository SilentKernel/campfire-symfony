<?php

declare(strict_types=1);

namespace App\Tests\Functional\Messages;

/**
 * UnfurlLinksController (reference/app/controllers/unfurl_links_controller.rb); the unfurling
 * itself is replayed against the reference in tests/Unit/Domain/Messages/UnfurlerTest.
 */
final class UnfurlLinksControllerTest extends MessagesTestCase
{
    public function testUnfurlsNothingFromPrivateAddressesAndNeedsAUrl(): void
    {
        $this->signIn('users.david');

        self::assertSame(204, $this->submit('POST', '/unfurl_link', ['url' => 'http://127.0.0.1/secret'])->getStatusCode());
        self::assertSame(204, $this->submit('POST', '/unfurl_link', ['url' => 'http://169.254.169.254/latest/meta-data/'])->getStatusCode());
        self::assertSame(400, $this->submit('POST', '/unfurl_link', ['url' => ''])->getStatusCode());
        self::assertSame(400, $this->submit('POST', '/unfurl_link')->getStatusCode());
        self::assertSame(204, $this->submit('POST', '/unfurl_link', ['url' => ['a' => 'http://example.com']])->getStatusCode());
        self::assertSame(500, $this->submit('POST', '/unfurl_link', ['url' => 'mailto:foo'])->getStatusCode());
    }

    public function testRequiresSignIn(): void
    {
        $this->client->request('POST', '/unfurl_link', ['url' => 'http://example.com']);

        // require_authentication runs before the forgery check.
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertSame('http://localhost/session/new', $this->client->getResponse()->headers->get('Location'));
    }
}
