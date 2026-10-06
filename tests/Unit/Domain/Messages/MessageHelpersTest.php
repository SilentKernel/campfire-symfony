<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Messages;

use App\Domain\Messages\Searches;
use App\Domain\Messages\Sound;
use App\Opengraph\Fetch;
use App\Opengraph\Metadata;
use App\Twig\MessagesExtension;
use App\View\MessagePresentation;
use PHPUnit\Framework\TestCase;

final class MessageHelpersTest extends TestCase
{
    public function testSounds(): void
    {
        self::assertCount(56, Sound::names());
        self::assertSame('56k', Sound::names()[0]);
        $bell = Sound::forPlainText('/play bell');
        self::assertNotNull($bell);
        self::assertSame('🔔', $bell->text);
        self::assertSame('bell.mp3', $bell->assetPath());
        self::assertSame(['sounds/top.webp', 188, 80], Sound::findByName('deeper')?->image);
        self::assertNull(Sound::forPlainText('/play nope'));
        self::assertNull(Sound::forPlainText(' /play bell'));
        self::assertNull(Sound::forPlainText("/play bell\n"));
    }

    public function testSearchQueriesKeepOnlyWordCharacters(): void
    {
        self::assertSame('hello  world ', Searches::sanitize('hello, world!'));
        self::assertSame('café_1 日本', Searches::sanitize('café_1 日本'));
        self::assertSame(' quoted  AND x', Searches::sanitize('"quoted" AND-x'));
        self::assertNull(Searches::sanitize(null));
    }

    public function testAllEmoji(): void
    {
        self::assertTrue(MessagePresentation::allEmoji('🎉🔥'));
        self::assertTrue(MessagePresentation::allEmoji('❤️'));
        self::assertFalse(MessagePresentation::allEmoji('🎉 🔥'));
        self::assertFalse(MessagePresentation::allEmoji('+1'));
        self::assertFalse(MessagePresentation::allEmoji(''));
    }

    public function testEpochMilliseconds(): void
    {
        self::assertSame(1772355600000, MessagesExtension::epoch(new \DateTimeImmutable('2026-03-01 09:00:00 UTC')));
        self::assertSame(1791188688576, MessagesExtension::epoch(new \DateTimeImmutable('2026-10-05 08:24:48.576123 UTC')));
    }

    public function testNetHttpContentTypeAndLength(): void
    {
        self::assertSame('text/html', Fetch::contentType('text/html; charset=utf-8'));
        self::assertSame('Text/HTML', Fetch::contentType('Text/HTML ; charset=utf-8'));
        self::assertNull(Fetch::contentType(null));
        self::assertSame(42, Fetch::contentLength(' 42 '));
        self::assertSame(0, Fetch::contentLength(null));
    }

    /** Probed against the reference (`strip_tags` then `sanitize`), as the Rust port records them. */
    public function testTitlesAndDescriptionsAreStrippedLikeRails(): void
    {
        foreach ([
            'Tom & Jerry' => 'Tom &amp; Jerry',
            'a < b' => 'a &lt; b',
            'x&nbsp;y' => 'x&nbsp;y',
            "\u{a0}nb" => '&nbsp;nb',
            "Hey!<script>alert('hi')</script>" => "Hey!alert('hi')",
            '<!-- c -->t' => 't',
            'a &lt;b&gt; c' => 'a &lt;b&gt; c',
            '<p>one</p><p>two</p>' => 'onetwo',
            '"q" \'a\'' => '"q" \'a\'',
            '<style>x</style>y' => 'xy',
            '&amp;amp;' => '&amp;amp;',
            '<b>bold</b>' => 'bold',
            '</script><img src=a onerror=prompt(1)>' => '',
            ' sp  ' => ' sp  ',
            '<textarea>t<b>x</b></textarea>' => 't&lt;b&gt;x&lt;/b&gt;',
        ] as $input => $expected) {
            self::assertSame($expected, Metadata::stripTags($input), $input);
        }
    }
}
