<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rooms;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The room pages against the Rails reference (fixtures/: captured as David from a fresh copy of
 * the seed with `curl/8.7.1`, after visiting rooms.group_direct), whole pages: the message list,
 * its template, the notification help and the join link included.
 */
final class RoomPagesParityTest extends RoomsTestCase
{
    /** @return iterable<string, array{string, string, array<string, string>}> */
    public static function pages(): iterable
    {
        yield 'room' => ['show_hq.html', '/rooms/201306877', []];
        yield 'original room with the invitation' => ['show_pets.html', '/rooms/104393281', []];
        yield 'direct room' => ['show_direct.html', '/rooms/186869642', []];
        yield 'group direct room' => ['show_group_direct.html', '/rooms/699448329', []];
        yield 'sidebar frame' => ['sidebar_frame.html', '/users/me/sidebar', ['HTTP_TURBO_FRAME' => 'user_sidebar']];
        yield 'sidebar page' => ['sidebar.html', '/users/me/sidebar', []];
        yield 'new open room' => ['opens_new.html', '/rooms/opens/new', []];
        yield 'new closed room' => ['closeds_new.html', '/rooms/closeds/new', []];
        yield 'edit open room' => ['opens_edit_hq.html', '/rooms/opens/201306877/edit', []];
        yield 'edit closed room' => ['closeds_edit_designers.html', '/rooms/closeds/654632876/edit', []];
        yield 'closed room as open' => ['opens_edit_designers.html', '/rooms/opens/654632876/edit', []];
        yield 'new direct room frame' => ['directs_new_frame.html', '/rooms/directs/new', ['HTTP_TURBO_FRAME' => 'direct_rooms_control']];
        yield 'edit direct room' => ['directs_edit_group.html', '/rooms/directs/699448329/edit', []];
        yield 'involvement frame' => ['involvement_hq_frame.html', '/rooms/201306877/involvement', ['HTTP_TURBO_FRAME' => 'involvement_rooms_open_201306877']];
        yield 'involvement page' => ['involvement_direct.html', '/rooms/186869642/involvement', []];
        yield 'mention prompt' => ['autocomplete.html', '/autocompletable/users', []];
        yield 'mention prompt in a room' => ['autocomplete_room.html', '/autocompletable/users?room_id=201306877&filter=e', []];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('pages')]
    public function testPageMatchesRails(string $fixture, string $path, array $headers): void
    {
        $this->signIn('users.david');
        self::setRawCookie($this->client, 'last_room', (string) self::id('rooms.group_direct'));
        $response = $this->get($path, $headers + ['HTTP_USER_AGENT' => 'curl/8.7.1']);
        self::assertSame(200, $response->getStatusCode());

        $rails = (string) file_get_contents(__DIR__.'/fixtures/'.$fixture);
        $bodyOnly = !isset($headers['HTTP_TURBO_FRAME']) && str_contains($rails, '<body class=');

        self::assertSame(
            implode("\n", RailsDom::lines($rails, [], $bodyOnly)),
            implode("\n", RailsDom::lines((string) $response->getContent(), [], $bodyOnly)),
        );
    }

    public function testFrameRequestsGetTheMinimalLayoutWithTheHead(): void
    {
        $this->signIn('users.david');
        $html = (string) $this->get('/users/me/sidebar', ['HTTP_TURBO_FRAME' => 'user_sidebar'])->getContent();

        self::assertStringStartsWith("<html>\n  <head>\n    <meta name=\"csrf-param\" content=\"authenticity_token\" />", $html);
        self::assertStringNotContainsString('<nav id="nav">', $html);
    }

    public function testJsonSuggestionsMatchRails(): void
    {
        $this->signIn('users.david');
        $response = $this->get('/autocompletable/users.json?query=j');

        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSame('2', $response->headers->get('X-Total-Count'));
        self::assertFalse($response->headers->has('Link'));
        self::assertSame(
            str_replace(RailsDom::REFERENCE_HOST, RailsDom::HOST, (string) file_get_contents(__DIR__.'/fixtures/autocomplete.json')),
            $response->getContent(),
        );
    }

    public function testRefreshMatchesRails(): void
    {
        $this->signIn('users.david');
        $response = $this->get('/rooms/486777696/refresh?since=1772400000000', ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);

        self::assertSame('text/vnd.turbo-stream.html; charset=utf-8', $response->headers->get('Content-Type'));
        $rails = (string) file_get_contents(__DIR__.'/fixtures/refresh.turbo_stream.html');
        self::assertSame(self::streams($rails), self::streams((string) $response->getContent()));
        // The messages they carry.
        self::assertSame(implode("\n", RailsDom::lines($rails, [], false)), implode("\n", RailsDom::lines((string) $response->getContent(), [], false)));
    }

    /** @return list<string> each stream's action, target and number of messages */
    private static function streams(string $html): array
    {
        preg_match_all('#<turbo-stream action="([^"]+)" target="([^"]+)"><template>(.*?)</template></turbo-stream>#s', $html, $matches, \PREG_SET_ORDER);

        return array_map(static function (array $match): string {
            return $match[1].' '.$match[2].' '.preg_match_all('/<div (?:id="[^"]*" )?class="message /', $match[3]);
        }, $matches);
    }
}
