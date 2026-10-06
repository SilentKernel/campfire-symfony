<?php

declare(strict_types=1);

namespace App\Tests\Functional\Messages;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Our message, boost and search pages against the Rails reference's (fixtures/, captured from
 * campfire-reference:app on the parity seed, signed in as David): the same bytes once
 * authenticity tokens, asset digests and the host are normalized.
 */
final class MessagesParityTest extends MessagesTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function messagePages(): iterable
    {
        yield 'last page (every presentation: text, code, table, mentions, sounds, embeds, image, video, files, boosts)' => ['designers_index.html', '/rooms/{rooms.designers}/messages'];
        yield 'page before' => ['designers_before.html', '/rooms/{rooms.designers}/messages?before={messages.image}'];
        yield 'page after' => ['designers_after.html', '/rooms/{rooms.designers}/messages?after={messages.image}'];
        yield 'direct room names' => ['direct_index.html', '/rooms/{rooms.david_and_jason}/messages'];
        yield 'unrenderable message' => ['broken_index.html', '/rooms/{rooms.broken}/messages'];
    }

    #[DataProvider('messagePages')]
    public function testMessagesIndexMatchesRails(string $fixture, string $path): void
    {
        $this->signIn('users.david');
        $response = $this->get(self::path($path));

        self::assertSame(200, $response->getStatusCode());
        self::assertSameHtml(self::railsFixture($fixture), self::normalize((string) $response->getContent()));
    }

    /** @return iterable<string, array{string, string}> */
    public static function layoutPages(): iterable
    {
        yield 'show' => ['show_edited.html', '/rooms/{rooms.designers}/messages/{messages.edited}'];
        yield 'edit text' => ['edit_edited.html', '/rooms/{rooms.designers}/messages/{messages.edited}/edit'];
        yield 'edit attachment' => ['edit_image.html', '/rooms/{rooms.designers}/messages/{messages.image}/edit'];
        yield 'boosts index' => ['boosts_index.html', '/messages/{messages.boosted_many}/boosts'];
        yield 'new boost' => ['boosts_new.html', '/messages/{messages.boosted_many}/boosts/new'];
        yield 'search results' => ['search_launch.html', '/searches?q=launch'];
        yield 'search page' => ['search_empty.html', '/searches'];
    }

    #[DataProvider('layoutPages')]
    public function testPagesMatchRails(string $fixture, string $path): void
    {
        $this->signIn('users.david');
        $response = $this->get(self::path($path));

        self::assertSame(200, $response->getStatusCode());
        self::assertSameHtml(self::main(self::railsFixture($fixture)), self::main(self::normalize((string) $response->getContent())));
    }

    public function testSearchPageNavAndSidebarMatchRails(): void
    {
        $this->signIn('users.david');
        $ours = self::normalize((string) $this->get('/searches?q=launch')->getContent());
        $rails = self::railsFixture('search_launch.html');

        foreach (['<nav id="nav">' => '</nav>', '<aside id="sidebar"' => '</aside>', '<title>' => '</title>', '<body' => '>'] as $start => $end) {
            self::assertSame(self::between($rails, $start, $end), self::between($ours, $start, $end), $start);
        }
    }

    public function testTheComposersMessageTemplateMatchesRails(): void
    {
        $this->signIn('users.david');
        $ours = self::normalize((string) $this->get('/rooms/'.self::id('rooms.watercooler'))->getContent());

        $start = '<script type="text/template" data-messages-target="template">';
        self::assertSame(self::between(self::railsFixture('room_watercooler.html'), $start, '</script>'), self::between($ours, $start, '</script>'));
    }

    public function testRoomNotFoundMatchesRails(): void
    {
        $this->signIn('users.david');
        $response = $this->submit('POST', '/rooms/999/messages', ['message' => ['body' => '<p>x</p>']], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertSameHtml(self::main(self::railsFixture('room_not_found.html')), self::main(self::normalize((string) $response->getContent())));
    }

    private static function path(string $template): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+\.[a-z_]+)\}/', static fn (array $m): string => (string) self::labels($m[1]), $template);
    }

    private static function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        self::assertNotFalse($from, $start);
        $to = strpos($html, $end, $from + \strlen($start));

        return substr($html, $from, false === $to ? null : $to - $from);
    }
}
