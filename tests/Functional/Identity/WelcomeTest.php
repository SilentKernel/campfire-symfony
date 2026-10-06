<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

/** WelcomeController#show: the last room visited, or the empty state. */
final class WelcomeTest extends IdentityTestCase
{
    public function testRedirectsToTheOriginalRoom(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        $response = $this->get($client, '/');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('http://localhost/rooms/'.self::id('rooms.pets'), $response->headers->get('Location'));
    }

    public function testRedirectsToTheLastRoomVisited(): void
    {
        $client = $this->client();
        $this->signIn($client, 'david');
        self::setRawCookie($client, 'last_room', (string) self::id('rooms.hq'));

        self::assertSame('http://localhost/rooms/'.self::id('rooms.hq'), $this->get($client, '/')->headers->get('Location'));
    }

    public function testUserWithoutRoomsSeesTheEmptyState(): void
    {
        $client = $this->client();
        $this->signIn($client, 'loner');
        $response = $this->get($client, '/');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        self::assertStringContainsString('<title>No rooms yet</title>', $html);
        self::assertStringContainsString('<body class="sidebar"', $html);
        self::assertStringContainsString('id="user_sidebar" src="/users/me/sidebar" target="_top"', $html);
        self::assertStringContainsString('<span class="for-screen-reader">Lonely Lou</span>', $html);
    }
}
