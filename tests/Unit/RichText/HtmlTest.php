<?php

declare(strict_types=1);

namespace App\Tests\Unit\RichText;

use App\RichText\Html\Html;
use App\RichText\Html\ParseError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parsing and serialization as Nokogiri::HTML5 (Gumbo) does them. The expected outputs are
 * `Nokogiri::HTML5.fragment(html).to_html` in the reference image (Nokogiri 1.19.4).
 */
final class HtmlTest extends TestCase
{
    /** @return iterable<array{string, string}> */
    public static function nokogiri(): iterable
    {
        yield ['<td>x</td>', 'x'];
        yield ['<p><table><tr><td>a</td></tr></table>', '<p></p><table><tbody><tr><td>a</td></tr></tbody></table>'];
        yield ["<a title='a<b>c' href=\"x&y\u{a0}z\">t&lt;\u{a0}>\"'</a>", "<a title=\"a<b>c\" href=\"x&amp;y&nbsp;z\">t&lt;&nbsp;&gt;\"'</a>"];
        yield ["<pre>\n\nx</pre>", "<pre>\nx</pre>"];
        yield ['<noscript><b>x</b></noscript>', '<noscript><b>x</b></noscript>'];
        yield ['<?php x ?>', '<!--?php x ?-->'];
        yield ["<SVG viewBox='0 0 1 1'><CLIPPATH/></SVG>", '<svg viewBox="0 0 1 1"><clipPath></clipPath></svg>'];
        yield ["\u{feff}\u{feff}x", "\u{feff}x"];
        yield ['<p title=a TITLE=b id=c title=d>x</p>', '<p title="a" id="c">x</p>'];
        yield ['<template><td>x</td><b>y</b></template>', '<template><td>x</td><b>y</b></template>'];
        // Gumbo's (older) select parsing
        yield ['<select><option>a<input>b</select>c', '<select><option>a</option></select><input>bc'];
        yield ['<select>x<textarea>y</textarea>z</select>w', '<select>x</select><textarea>y</textarea>zw'];
        yield ['<select><b>x</b><div>y</div><p>z</select>', '<select>xyz</select>'];
        yield ['<select><svg><b>x', '<select>x</select>'];
        yield ['<select><option>a</option><span>b</span>c</select>', '<select><option>a</option>bc</select>'];
        yield ['<p><select><li>x</select>', '<p><select>x</select></p>'];
        yield ['<select><option>a<b>c<textarea>q</textarea>d</b>e</option>f</select>g', '<select><option>ac</option></select><textarea>q</textarea>defg'];
        // Where Lexbor departs from the parsing algorithm
        yield ['<svg><sup>x', '<svg></svg><sup>x</sup>'];
        yield ['<math><sup>x', '<math></math><sup>x</sup>'];
        yield ['<strong><font>x</strong><ol><textarea>y</textarea>', '<strong><font>x</font></strong><ol><textarea>y</textarea></ol>'];
    }

    #[DataProvider('nokogiri')]
    public function testParsesAndSerializesLikeNokogiri(string $html, string $expected): void
    {
        $this->assertSame($expected, Html::toHtml(Html::fragment($html)));
    }

    public function testEscapesAttributeBracketsOnRequest(): void
    {
        $this->assertSame('<a title="a&lt;b&gt;c">d&gt;e</a>', Html::toHtml(Html::fragment("<a title='a<b>c'>d>e</a>"), escapeAttributeBrackets: true));
    }

    /** @return iterable<array{string, bool}> */
    public static function limits(): iterable
    {
        $b = static fn (int $n): string => str_repeat('<b>', $n);
        $attributes = static fn (int $from, int $to): string => implode(' ', array_map(static fn (int $i): string => "a$i=$i", range($from, $to)));
        yield [$b(400), true];
        yield [$b(401), false];
        yield [$b(400).'<br>', true];
        yield [$b(401).str_repeat('</b>', 401), false];
        yield [str_repeat('<b>'.str_repeat('<span>', 300).str_repeat('<div>', 10).'</b>', 3), true];
        yield ['<b>'.str_repeat('<span>', 390).str_repeat('<div>', 10).'</b>'.str_repeat('<div>', 300), false];
        yield [str_repeat('<p>x', 500), true];
        yield ['<p '.$attributes(1, 400).'>x</p>', true];
        yield ['<p '.$attributes(1, 401).'>x</p>', false];
        yield ['<p '.$attributes(1, 400).' a1=again>x</p>', false];
        yield ['<p>x</p '.$attributes(1, 401).'>', false];
        yield ['<p '.$attributes(1, 400).' a401', false];
        yield ['<textarea><p '.$attributes(1, 401).'>', true];
    }

    #[DataProvider('limits')]
    public function testEnforcesGumboLimits(string $html, bool $parses): void
    {
        try {
            Html::fragment($html);
            $this->assertTrue($parses, 'Gumbo refuses this markup');
        } catch (ParseError) {
            $this->assertFalse($parses, 'Gumbo parses this markup');
        }
    }
}
