<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\Html\Tag;
use PHPUnit\Framework\TestCase;
use Twig\Markup;

/** Expected markup is ActionView 8.2's (TagHelper). */
final class TagTest extends TestCase
{
    public function testAttributesKeepOrderAndEscapeLikeErb(): void
    {
        self::assertSame(
            '<details class="position-relative" data-controller="popup" data-action="keydown.esc-&gt;popup#close" data-popup-orientation-top-class="popup-orientation-top"></details>',
            Tag::content('details', null, ['class' => 'position-relative', 'data' => [
                'controller' => 'popup', 'action' => 'keydown.esc->popup#close', 'popup_orientation_top_class' => 'popup-orientation-top',
            ]]),
        );
        self::assertSame(' title="&#39;a&#39; &amp; &quot;b&quot;"', Tag::options(['title' => '\'a\' & "b"']));
    }

    public function testNilFalseTrueAndBooleanAttributes(): void
    {
        self::assertSame('<meta name="vapid-public-key">', Tag::void('meta', ['name' => 'vapid-public-key', 'content' => null]));
        self::assertSame(' required="required" autofocus="autofocus"', Tag::options(['required' => true, 'disabled' => false, 'autofocus' => 'yes']));
        self::assertSame(' draggable="false" tabindex="-1"', Tag::options(['draggable' => false, 'tabindex' => -1]));
    }

    public function testDataAndAriaHashes(): void
    {
        self::assertSame(
            ' data-turbo-frame="_top" data-turbo-permanent="true" data-ids="[1,2]" data-config="{&quot;a&quot;:&quot;\u003cb\u003e&quot;}" data-count="3" aria-hidden="true" aria-describedby="a b"',
            Tag::options([
                'data' => ['turbo_frame' => '_top', 'turbo_permanent' => true, 'ids' => [1, 2], 'config' => ['a' => '<b>'], 'count' => 3, 'skipped' => null],
                'aria' => ['hidden' => true, 'describedby' => ['a', 'b']],
            ]),
        );
    }

    public function testClassArraysAndTokenLists(): void
    {
        self::assertSame(' class="btn active"', Tag::options(['class' => ['btn', ['active' => true, 'hidden' => false], null, '']]));
        self::assertSame('a b c', Tag::tokenList('a b', ['b' => true, 'c' => 1, 'd' => false]));
    }

    public function testMarkupIsNotEscapedExceptQuotes(): void
    {
        self::assertSame(' value="&amp; &quot;"', Tag::options(['value' => new Markup('&amp; "', 'UTF-8')]));
        self::assertSame('<span><b>x</b></span>', Tag::content('span', new Markup('<b>x</b>', 'UTF-8')));
        self::assertSame('<span>&lt;b&gt;</span>', Tag::content('span', '<b>'));
    }

    public function testTagShapes(): void
    {
        self::assertSame('<meta name="current-user-id" content="1" />', Tag::legacy('meta', ['name' => 'current-user-id', 'content' => 1]));
        self::assertSame('<form action="/x">', Tag::legacy('form', ['action' => '/x'], true));
        self::assertSame("<textarea>\nhi</textarea>", Tag::content('textarea', 'hi'));
        self::assertSame('<time datetime="x"></time>', Tag::content('time', null, ['datetime' => 'x']));
    }
}
