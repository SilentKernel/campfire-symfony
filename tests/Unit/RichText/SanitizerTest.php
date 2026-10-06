<?php

declare(strict_types=1);

namespace App\Tests\Unit\RichText;

use App\RichText\Sanitizer\CssScrubber;
use App\RichText\Sanitizer\SafeList;
use App\RichText\Sanitizer\SafeListSanitizer;
use App\RichText\Sanitizer\UriSafety;
use PHPUnit\Framework\TestCase;

/** rails-html-sanitizer's PermitScrubber over Loofah; expected outputs from the reference image. */
final class SanitizerTest extends TestCase
{
    public function testScrubsLikeRails(): void
    {
        $list = SafeList::contentFilter();
        $this->assertSame('<div><a>x</a></div>', SafeListSanitizer::sanitize('<div><a href="javascript:alert(1)">x</a></div>', $list));
        $this->assertSame('<a href="/x">x</a>', SafeListSanitizer::sanitize('<a href="/x" onmouseover="alert(1)">x</a>', $list));
        $this->assertSame('<a>x</a>', SafeListSanitizer::sanitize('<a href="data:text/html,pwned">x</a>', $list));
        $this->assertSame('<a href="a%20b">x</a>', SafeListSanitizer::sanitize('<a href="a b">x</a><!-- c -->', $list));
        $this->assertSame('yz', SafeListSanitizer::sanitize('<svg><a>x</a></svg>y<script>z</script>', $list));
    }

    public function testReEscapesUrlAttributesAsEachAttributeIsScrubbed(): void
    {
        $list = new SafeList(['img', 'a'], ['src', 'href', 'name', 'title', 'alt']);
        // The blank src is removed, and the escaping it still triggers lets the href through
        $this->assertSame('<img href="%20javascript:alert(1)">', SafeListSanitizer::sanitize('<img src=" " href=" javascript:alert(1)">', $list));
        $this->assertSame('<img>', SafeListSanitizer::sanitize('<img href=" javascript:alert(1)">', $list));
        $this->assertSame('<a href="a%20b" name="c%20d" title="x y">t</a>', SafeListSanitizer::sanitize('<a href="a b" name="c d" title="x y">t</a>', $list));
    }

    public function testScrubsCssLikeLoofah(): void
    {
        $this->assertSame('white-space:pre-wrap;', CssScrubber::scrub('white-space: pre-wrap;'));
        $this->assertSame('color:var(--highlight-1);background-color:var(--highlight-bg-2);', CssScrubber::scrub('color: var(--highlight-1); background-color: var(--highlight-bg-2);'));
        $this->assertSame('color:red;', CssScrubber::scrub('color: red; background-image: url(javascript:alert(1))'));
        $this->assertSame('background-color:rgb(255, 240, 0);', CssScrubber::scrub('background-color: rgb(255, 240, 0)'));
        $this->assertSame('', CssScrubber::scrub('width: expression(alert(1));'));
        $this->assertSame('', CssScrubber::scrub('position: fixed'));
        $this->assertSame('color:red !important;', CssScrubber::scrub('COLOR: red !important'));
    }

    public function testChecksUrisLikeLoofah(): void
    {
        $this->assertFalse(UriSafety::allowedUri('javascript:alert(1)'));
        $this->assertFalse(UriSafety::allowedUri("java\nscript:alert(1)"));
        $this->assertFalse(UriSafety::allowedUri('javascript&#58;alert(1)'));
        $this->assertFalse(UriSafety::allowedUri('&#106;avascript:alert(1)'));
        $this->assertFalse(UriSafety::allowedUri('javascript&colon;alert(1)'));
        $this->assertTrue(UriSafety::allowedUri('/rooms/1'));
        $this->assertTrue(UriSafety::allowedUri('https://example.com'));
        $this->assertTrue(UriSafety::allowedUri('data:image/png;base64,xx'));
        $this->assertFalse(UriSafety::allowedUri('data:text/html,xx'));
        $this->assertSame('<a>x</a>', SafeListSanitizer::sanitize('<a href="&amp;#0000000000038;#106;avascript:alert(1)">x</a>', SafeList::contentFilter()));
    }

    public function testUnescapesHtmlLikeCgi(): void
    {
        foreach ([
            '&#65;' => 'A', '&#X41;' => 'A', '&#00000000065;' => 'A', '&#x000000041;' => 'A', '&#0;' => "\0", '&#1114110;' => "\u{10fffe}",
            '&#1114111;' => '&#1114111;', '&#x110000;' => '&#x110000;', '&#99999999999999999999;' => '&#99999999999999999999;',
            '&#;' => '&#;', '&#65' => '&#65', '&#0x41;' => '&#0x41;', '&amp;#65;' => '&#65;',
        ] as $escaped => $unescaped) {
            $this->assertSame($unescaped, UriSafety::cgiUnescapeHtml((string) $escaped), (string) $escaped);
        }
    }
}
