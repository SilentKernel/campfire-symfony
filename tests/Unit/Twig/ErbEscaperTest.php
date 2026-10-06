<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\Escaper\ErbEscaper;
use App\Twig\Escaper\ErbEscaperExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Markup;

final class ErbEscaperTest extends TestCase
{
    /** ERB::Util.html_escape("' & < > \"") */
    private const ERB = '&#39; &amp; &lt; &gt; &quot;';

    public function testHtmlMatchesErb(): void
    {
        self::assertSame(self::ERB, ErbEscaper::html('\' & < > "'));
        self::assertSame('&amp;#39; caf&amp;#233;', ErbEscaper::html('&#39; caf&#233;'));
        self::assertSame('naïve 🔥', ErbEscaper::html('naïve 🔥'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function templates(): iterable
    {
        yield 'text' => ['{{ s }}', self::ERB];
        yield 'attribute' => ['<a title="{{ s }}">', '<a title="'.self::ERB.'">'];
        yield 'escape filter' => ['{% autoescape false %}{{ s|e }}{% endautoescape %}', self::ERB];
        yield 'escape html filter' => ['{% autoescape false %}{{ s|escape("html") }}{% endautoescape %}', self::ERB];
        yield 'html_attr is ERB too' => ['{% autoescape false %}{{ s|e("html_attr") }}{% endautoescape %}', self::ERB];
        yield 'explicit filter is not doubled' => ['{{ s|e }}', self::ERB];
        yield 'concatenation' => ['{{ "x" ~ s }}', 'x'.self::ERB];
        yield 'ternary' => ['{{ true ? s : "" }}', self::ERB];
        yield 'raw' => ['{{ s|raw }}', '\' & < > "'];
        yield 'markup' => ['{{ m }}', '<b>\'</b>'];
        yield 'stringable' => ['{{ o }}', self::ERB];
        yield 'js strategy is Twig\'s' => ['{{ "\'"|e("js") }}', '\\u0027'];
        yield 'autoescape html block' => ['{% autoescape "html" %}{{ s }}{% endautoescape %}', self::ERB];
        yield 'null' => ['[{{ n }}]', '[]'];
    }

    #[DataProvider('templates')]
    public function testTwigEscapesLikeErb(string $template, string $expected): void
    {
        $twig = new Environment(new ArrayLoader(['t' => $template]), ['autoescape' => 'html', 'strict_variables' => true]);
        $twig->addExtension(new ErbEscaperExtension());

        self::assertSame($expected, $twig->render('t', [
            's' => '\' & < > "',
            'm' => new Markup('<b>\'</b>', 'UTF-8'),
            'o' => new class implements \Stringable {
                public function __toString(): string
                {
                    return '\' & < > "';
                }
            },
            'n' => null,
        ]));
    }
}
