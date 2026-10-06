<?php

declare(strict_types=1);

namespace App\Tests\Unit\RichText;

use App\RichText\AutoLink;
use App\RichText\Sanitizer\SafeList;
use App\RichText\TagIndex;
use PHPUnit\Framework\TestCase;

final class AutoLinkTest extends TestCase
{
    /** rails_autolink's `auto_linked?`, as regular expressions over the whole of `left`. */
    private static function autoLinkedByRegex(string $left, string $right): bool
    {
        if (preg_match('/<[^>]+$/m', $left) && preg_match('/^[^>]*>/m', $right)) {
            return true;
        }
        for ($i = \strlen($left) - 1; $i >= 0; --$i) {
            if (preg_match('/\G<a\b[^\n]*?>/i', $left, $m, 0, $i)) {
                return !preg_match('~</a>~i', substr($left, $i + \strlen($m[0])));
            }
        }

        return false;
    }

    public function testTagIndexAnswersAsTheRegularExpressionsDo(): void
    {
        foreach ([
            '<p>www.a.com</p><p>b</p>', "<p title=\"a\nb\">x</p> y <p>z</p>", "<p title=\"a\n\">x</p>",
            "<a href=\"x\">in <b>link</b></a> out <A\nhref=\"y\">z</A> <a>q</a>", "<ab>x</ab><a\tclass=\"c\">y", "x<\ny>z<é>", '<', '<a>', '',
        ] as $text) {
            $index = new TagIndex($text);
            for ($start = 0; $start <= \strlen($text); ++$start) {
                for ($end = $start; $end <= \strlen($text); ++$end) {
                    $this->assertSame(self::autoLinkedByRegex(substr($text, 0, $start), substr($text, $end)), $index->autoLinked($start, $end), json_encode($text)." at $start..$end");
                }
            }
        }
    }

    public function testLinksUrlsAndEmailAddresses(): void
    {
        $this->assertSame(
            '<p>see <a target="_blank" href="http://example.com/a?b=1&amp;c=2">http://example.com/a?b=1&amp;c=2</a> and <a target="_blank" href="mailto:me@example.com">me@example.com</a></p>',
            AutoLink::autoLink('<p>see http://example.com/a?b=1&amp;c=2 and me@example.com</p>', SafeList::autoLink()),
        );
        $this->assertSame('(<a target="_blank" href="http://www.example.com/(a)">www.example.com/(a)</a>).', AutoLink::autoLink('(www.example.com/(a)).', SafeList::autoLink()));
    }

    public function testCannotBreakOutOfAnAttributeValue(): void
    {
        // Rails serializes the title unescaped, so its auto_link inserts a link inside it, whose
        // quote closes the attribute and turns the <img> after it into markup
        $html = AutoLink::autoLink('<p title="x> http://evil.test/ <img src=x onerror=alert(1)>">hi</p>', SafeList::autoLink());
        $this->assertSame('<p title="x&gt; http://evil.test/ &lt;img src=x onerror=alert(1)&gt;">hi</p>', $html);
    }
}
