<?php

declare(strict_types=1);

namespace App\Tests\Functional\Search;

use App\Tests\Functional\Messages\MessagesTestCase;

/**
 * SearchesController (reference/app/controllers/searches_controller.rb) and Search.record.
 */
final class SearchesControllerTest extends MessagesTestCase
{
    public function testCreateRecordsTheSanitizedQueryAndRedirects(): void
    {
        $this->signIn('users.david');
        $response = $this->submit('POST', '/searches', ['q' => 'hello, world']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('http://localhost/searches?q=hello++world', $response->headers->get('Location'));
        self::assertSame('2026-03-02 16:00:00', $this->fetchValue('SELECT updated_at FROM searches WHERE user_id = ? AND query = ?', self::id('users.david'), 'hello  world'));

        $page = (string) $this->get('/searches?q=hello++world')->getContent();
        self::assertStringContainsString('<span class="overflow-ellipsis">“hello  world”</span>', $page);
        self::assertStringContainsString('<input value="hello  world" class="searches__input', $page);
    }

    public function testRecordingAnExistingQueryTouchesIt(): void
    {
        $this->signIn('users.david');
        $this->submit('POST', '/searches', ['q' => 'pizza']);

        self::assertSame(1, (int) $this->fetchValue('SELECT COUNT(*) FROM searches WHERE user_id = ? AND query = ?', self::id('users.david'), 'pizza'));
        self::assertSame('2026-03-02 16:00:00', $this->fetchValue('SELECT updated_at FROM searches WHERE id = ?', self::id('searches.david_pizza')));
    }

    public function testOnlyTheTenMostRecentSearchesAreKept(): void
    {
        $this->signIn('users.david');
        $user = self::id('users.david');
        for ($i = 1; $i <= 12; ++$i) {
            $this->connection()->insert('searches', ['user_id' => $user, 'query' => "old{$i}", 'created_at' => '2026-01-01 00:00:00', 'updated_at' => \sprintf('2026-01-01 00:00:%02d', $i)]);
        }
        $this->submit('POST', '/searches', ['q' => 'fresh']);

        $queries = $this->connection()->fetchFirstColumn('SELECT query FROM searches WHERE user_id = ? ORDER BY updated_at DESC', [$user]);
        self::assertCount(10, $queries);
        self::assertSame('fresh', $queries[0]);
        self::assertSame(['cuckoo', 'Borgias', 'pizza'], \array_slice($queries, 1, 3));
    }

    public function testClearDestroysTheUsersSearches(): void
    {
        $this->signIn('users.david');
        $response = $this->submit('DELETE', '/searches/clear');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('http://localhost/searches', $response->headers->get('Location'));
        self::assertSame(0, (int) $this->fetchValue('SELECT COUNT(*) FROM searches WHERE user_id = ?', self::id('users.david')));
    }

    public function testResultsAreTheUsersReachableMessagesOnly(): void
    {
        $this->signIn('users.jz');
        $page = (string) $this->get('/searches?q=launch')->getContent();

        // JZ is in Designers and HQ but not in All Talk.
        self::assertStringContainsString('<span class="flex-item-no-shrink">5</span>', $page);
        self::assertStringNotContainsString('data-message-id="'.self::id('messages.fourth').'"', $page);
    }

    public function testFtsOperatorsAreNeutralized(): void
    {
        $this->signIn('users.david');

        // Quotes, parentheses, asterisks and colons become spaces before reaching FTS5.
        $response = $this->get('/searches?q='.rawurlencode('"launch* (body:launch)'));
        self::assertSame(200, $response->getStatusCode());
        // " launch   body launch ": every term must match, as in Rails (no message says "body").
        self::assertStringContainsString('<span class="flex-item-no-shrink">0</span>', (string) $response->getContent());
        // A bare FTS5 keyword left dangling is a syntax error in Rails too.
        self::assertSame(500, $this->get('/searches?q=launch+AND')->getStatusCode());
    }

    public function testCreateWithoutAQueryFails(): void
    {
        $this->signIn('users.david');

        self::assertSame(500, $this->submit('POST', '/searches')->getStatusCode());
    }
}
