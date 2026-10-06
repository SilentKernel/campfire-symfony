<?php

declare(strict_types=1);

namespace App\Tests\Functional\Messages;

/**
 * Messages::BoostsController (reference/app/controllers/messages/boosts_controller.rb).
 */
final class BoostsControllerTest extends MessagesTestCase
{
    public function testCreateRedirectsTouchesAndBroadcastsLikeRails(): void
    {
        $this->signIn('users.david');
        $message = self::id('messages.unboosted');
        $this->broadcaster()->clear();

        $response = $this->submit('POST', "/messages/{$message}/boosts", ['boost' => ['content' => 'Nice one']], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame("http://localhost/messages/{$message}/boosts", $response->headers->get('Location'));
        $boost = $this->connection()->fetchAssociative('SELECT * FROM boosts WHERE message_id = ?', [$message]);
        self::assertIsArray($boost);
        self::assertSame('Nice one', $boost['content']);
        self::assertSame(self::id('users.david'), (int) $boost['booster_id']);
        self::assertSame('2026-03-02 16:00:00', $this->fetchValue('SELECT updated_at FROM messages WHERE id = ?', $message));
        self::assertSame('2026-03-02 16:00:00', $this->fetchValue('SELECT updated_at FROM rooms WHERE id = ?', self::id('rooms.designers')));

        $broadcast = $this->broadcaster()->broadcasts[0];
        self::assertIsString($broadcast['payload']);
        self::assertSame(
            self::withoutBoostIds(self::railsFixture('boost_create.broadcast.html')),
            self::withoutBoostIds(self::normalize($broadcast['payload'])),
        );
    }

    /** turbo-rails' FrameRequest: a Turbo-Frame request gets layouts/turbo_rails/frame. */
    public function testFrameRequestsGetTheMinimalLayout(): void
    {
        $this->signIn('users.david');
        $message = self::id('messages.boosted_many');

        foreach (["/messages/{$message}/boosts/new" => 'new_boost_message_', "/messages/{$message}/boosts" => 'boosting_message_'] as $path => $frame) {
            $html = (string) $this->get($path, ['HTTP_TURBO_FRAME' => 'x'])->getContent();
            self::assertStringStartsWith("<html>\n  <head>\n    <meta name=\"csrf-param\" content=\"authenticity_token\" />", $html);
            self::assertStringNotContainsString('<title>', $html);
            self::assertStringContainsString('<turbo-frame id="'.$frame, $html);

            $page = (string) $this->get($path)->getContent();
            self::assertStringStartsWith('<!DOCTYPE html>', $page);
        }
    }

    public function testDestroyOwnBoost(): void
    {
        $this->signIn('users.david');
        $message = self::id('messages.boosted_by_david');
        $boost = self::id('boosts.david_on_boosted_by_david');
        $this->broadcaster()->clear();

        $response = $this->submit('DELETE', "/messages/{$message}/boosts/{$boost}", [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml']);

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($this->fetchValue('SELECT 1 FROM boosts WHERE id = ?', $boost));
        self::assertSame('2026-03-02 16:00:00', $this->fetchValue('SELECT updated_at FROM messages WHERE id = ?', $message));
        self::assertSame("<turbo-stream action=\"remove\" target=\"boost_{$boost}\"></turbo-stream>", $this->broadcaster()->broadcasts[0]['payload']);
    }

    public function testSomeoneElsesBoostIsNotFound(): void
    {
        $this->signIn('users.david');
        $message = self::id('messages.boosted_one');

        self::assertSame(404, $this->submit('DELETE', "/messages/{$message}/boosts/".self::id('boosts.jz_on_boosted_one'))->getStatusCode());
        self::assertNotFalse($this->fetchValue('SELECT 1 FROM boosts WHERE id = ?', self::id('boosts.jz_on_boosted_one')));
    }

    public function testMessagesOutsideTheUsersRoomsAreNotFound(): void
    {
        $this->signIn('users.jz');
        $watercoolerMessage = self::id('messages.fourth');

        self::assertSame(404, $this->get("/messages/{$watercoolerMessage}/boosts")->getStatusCode());
        self::assertSame(404, $this->get("/messages/{$watercoolerMessage}/boosts/new")->getStatusCode());
        self::assertSame(404, $this->submit('POST', "/messages/{$watercoolerMessage}/boosts", ['boost' => ['content' => '👍']])->getStatusCode());
    }

    public function testCreateWithoutBoostParamsIs400(): void
    {
        $this->signIn('users.david');

        self::assertSame(400, $this->submit('POST', '/messages/'.self::id('messages.unboosted').'/boosts')->getStatusCode());
    }

    private static function withoutBoostIds(string $html): string
    {
        return (string) preg_replace('/boost(s?)_(\d+)|boosts\/\d+/', 'boost$1_ID', $html);
    }
}
