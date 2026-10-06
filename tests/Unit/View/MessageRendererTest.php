<?php

declare(strict_types=1);

namespace App\Tests\Unit\View;

use App\Domain\Messages\MessagePages;
use App\Entity\Message;
use App\Entity\Room;
use App\Http\Current;
use App\Tests\Support\CampfireTestCase;
use App\View\MessagePresentationLoader;
use App\View\MessagePresentationSource;
use App\View\MessageRenderer;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Twig\Environment;

/**
 * MessageRenderer's fragment caching (reference commit 659f957): one cache read for the page,
 * presentation data loaded only for the misses, identical HTML from the cache.
 */
final class MessageRendererTest extends CampfireTestCase
{
    private CountingSource $source;
    private MessageRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setEnv('CAMPFIRE_FROZEN_TIME', '2026-03-02T16:00:00Z');
        $container = static::getContainer();
        $loader = $container->get(MessagePresentationLoader::class);
        \assert($loader instanceof MessagePresentationLoader);
        $twig = $container->get('twig');
        \assert($twig instanceof Environment);
        $current = $container->get(Current::class);
        \assert($current instanceof Current);
        $this->source = new CountingSource($loader);
        $this->renderer = new MessageRenderer($twig, $this->source, $current, new ArrayAdapter());
    }

    public function testACacheHitRendersTheSameHtmlWithoutLoadingPresentationData(): void
    {
        $messages = $this->page();

        $first = $this->renderer->render($messages);
        self::assertSame([\count($messages)], $this->source->calls, 'Every message missed once, loaded in one batch.');
        self::assertSame(\count($messages), substr_count($first, "\n  <div id=\"message_"));

        $this->em()->clear();
        $second = $this->renderer->render($this->page());
        self::assertSame($first, $second);
        self::assertSame([\count($messages)], $this->source->calls, 'Nothing loaded on a full hit.');
    }

    public function testOnlyTheMessagesThatChangedAreLoadedAgain(): void
    {
        $this->renderer->render($this->page());
        $edited = self::id('messages.edited');
        $this->connection()->update('messages', ['updated_at' => '2026-03-02 16:00:00.5'], ['id' => $edited]);
        $this->em()->clear();

        $html = $this->renderer->render($this->page());

        self::assertSame([31, 1], $this->source->calls);
        self::assertStringContainsString('data-message-id="'.$edited.'" data-message-timestamp="1772367600000" data-message-updated-at="1772467200500"', $html);
    }

    public function testRenderOneAndAnEmptyPage(): void
    {
        self::assertSame('', $this->renderer->render([]));
        self::assertSame([], $this->source->calls);

        $message = $this->em()->find(Message::class, self::id('messages.plain'));
        \assert($message instanceof Message);
        $html = $this->renderer->renderOne($message);
        self::assertStringStartsWith("\n  <div id=\"message_", $html);
        self::assertSame($html, $this->renderer->renderOne($message));
        self::assertSame([1], $this->source->calls);
    }

    public function testDetachedRenderingHasNoAuthenticityTokens(): void
    {
        $message = $this->em()->find(Message::class, self::id('messages.unboosted'));
        \assert($message instanceof Message);

        $html = $this->renderer->renderDetached($message);

        self::assertStringNotContainsString('authenticity_token', $html);
        self::assertStringContainsString('data-copy-to-clipboard-content-value="http://example.org/rooms/', $html);
    }

    public function testAMessageWhoseCreatorIsGoneIsUnrenderable(): void
    {
        $message = $this->em()->find(Message::class, self::id('messages.unrenderable'));
        \assert($message instanceof Message);

        self::assertSame(
            "\n  <div class=\"message message--formatted message--failed center\">\n  <div class=\"message__body\">\n    <div class=\"message__body-content txt-align-center\">\n      Failed to load message content\n    </div>\n  </div>\n</div>\n",
            $this->renderer->renderOne($message),
        );
    }

    /** @return list<Message> */
    private function page(): array
    {
        $pages = static::getContainer()->get(MessagePages::class);
        \assert($pages instanceof MessagePages);
        $room = $this->em()->find(Room::class, self::id('rooms.designers'));
        \assert($room instanceof Room);

        return $pages->lastPage($room);
    }
}

/** Counts the batches (and their sizes) the renderer asks for. */
final class CountingSource implements MessagePresentationSource
{
    /** @var list<int> */
    public array $calls = [];

    public function __construct(private readonly MessagePresentationSource $inner)
    {
    }

    public function load(array $messages): array
    {
        $this->calls[] = \count($messages);

        return $this->inner->load($messages);
    }
}
