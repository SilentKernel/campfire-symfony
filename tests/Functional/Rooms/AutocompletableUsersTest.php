<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rooms;

use App\Rails\RailsJson;

/** Autocompletable::UsersController (reference/app/controllers/autocompletable/users_controller.rb). */
final class AutocompletableUsersTest extends RoomsTestCase
{
    public function testPromptItemsForEveryActiveUser(): void
    {
        $this->signIn('users.david');
        $response = $this->get('/autocompletable/users');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('<lexxy-prompt-item', ltrim((string) $response->getContent()));
        self::assertSame(
            ['Bender Bot', 'David', 'Deploy Bot', 'Jason', 'JZ', 'Kevin', 'Lonely Lou'],
            $this->crawler($response)->filter('lexxy-prompt-item')->each(static fn ($i): string => (string) $i->attr('search')),
        );
    }

    public function testFilterAndQueryInARoom(): void
    {
        $this->signIn('users.david');
        $room = self::id('rooms.designers');
        $names = fn (string $query): array => $this->crawler($this->get('/autocompletable/users?room_id='.$room.'&'.$query))->filter('lexxy-prompt-item')->each(static fn ($i): string => (string) $i->attr('search'));

        self::assertSame(['Jason', 'JZ'], $names('filter=J'));
        self::assertSame(['Jason', 'JZ'], $names('query=j'));
        self::assertSame(['David', 'Deploy Bot', 'Jason', 'JZ', 'Kevin'], $names('filter=&query='));
    }

    /** index.html.erb's final newline follows the (empty) collection: an ETag'd "\n" as in Rails. */
    public function testNoMatchesIsANewline(): void
    {
        $this->signIn('users.david');
        $response = $this->get('/autocompletable/users?filter=zzz');

        self::assertSame("\n", $response->getContent());
        self::assertSame('max-age=0, private, must-revalidate', $response->headers->get('Cache-Control'));
        self::assertSame('W/"'.substr(hash('sha256', "\n"), 0, 32).'"', $response->headers->get('ETag'));
        self::assertStringEndsWith("</lexxy-prompt-item>\n\n", (string) $this->get('/autocompletable/users?filter=J')->getContent());
    }

    public function testRoomsOutOfReachAreNotFound(): void
    {
        $this->signIn('users.jz');
        self::assertSame(404, $this->get('/autocompletable/users?room_id='.self::id('rooms.watercooler'))->getStatusCode());
        self::assertSame(404, $this->get('/autocompletable/users?room_id=nope')->getStatusCode());
    }

    public function testJsonIsPaginated(): void
    {
        $this->connection()->executeStatement(
            "WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 30) INSERT INTO users (name, role, status, created_at, updated_at) SELECT 'Zed <' || i || '>', 0, 0, '2026-02-10 10:00:00', '2026-02-10 10:00:00' FROM n",
        );
        $this->signIn('users.david');
        $response = $this->get('/autocompletable/users.json?query=e&page=1&b=x');

        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('34', $response->headers->get('X-Total-Count'));
        self::assertSame('<http://localhost/autocompletable/users.json?b=x&page=2&query=e>; rel="next"', $response->headers->get('Link'));
        $users = RailsJson::decode((string) $response->getContent());
        self::assertIsArray($users);
        self::assertCount(20, $users);
        self::assertSame(['name', 'value', 'avatar_url', 'sgid'], array_keys($users[0]));

        $last = $this->get('/autocompletable/users.json?query=e&page=2');
        self::assertFalse($last->headers->has('Link'));
        $users = RailsJson::decode((string) $last->getContent());
        self::assertIsArray($users);
        self::assertCount(14, $users);
        // h(user.name), then JSON-encoded the ActiveSupport way
        self::assertStringContainsString(substr(RailsJson::encode(['name' => 'Zed &lt;9&gt;']), 1, -1), (string) $last->getContent());
    }

    public function testHtmlHasNoPaginationHeaders(): void
    {
        $this->signIn('users.david');
        self::assertFalse($this->get('/autocompletable/users')->headers->has('X-Total-Count'));
        self::assertSame(406, $this->get('/autocompletable/users.xml')->getStatusCode());
    }
}
