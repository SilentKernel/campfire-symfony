<?php

declare(strict_types=1);

namespace App\Tests\Unit\RichText;

use App\Rails\RailsJson;
use App\RichText\Attachables\Attachments;
use App\RichText\AutoLink;
use App\RichText\Content;
use App\RichText\Filters\TextMessagePresentationFilters;
use App\RichText\Html\Html;
use App\RichText\RichTextError;
use App\RichText\Ruby;
use App\RichText\Sanitizer\SafeList;
use Dom\Element;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Differential test against Rails' own rich text pipeline: tests/fixtures/richtext/corpus.json
 * (658 stored bodies, 400 of them fuzzed, with what Rails renders for each) and canonical.json
 * (what Action Text stores for them), both generated in the reference image.
 *
 * Every output must match Rails byte for byte, except the cases listed in KNOWN_DIFFERENCES:
 * the set of mismatches is asserted exactly, so a regression and a fix both show up.
 */
final class CorpusTest extends TestCase
{
    /**
     * Fuzzed bodies whose garbage markup Lexbor (PHP 8.4) and Gumbo (Nokogiri 1.19) parse into
     * different trees, beyond what App\RichText\Html\Html rewrites: a formatting element Gumbo
     * reconstructs after a textarea (fuzz 14), a raw text element inside a select closed by tags
     * the old select parsing reads differently (168, 341), a newline after an ignored <pre> in a
     * select (320), formatting elements reconstructed around a gallery attachment (225), and an
     * attachment rendered inside SVG content (118). "xss name attribute": `name` attributes are
     * dropped on purpose (DOM clobbering); the presentation drops them in Rails too, so only the
     * intermediate outputs differ.
     */
    private const array KNOWN_DIFFERENCES = [
        'presentation' => ['fuzz 168', 'fuzz 225'],
        'filtered' => ['fuzz 168', 'fuzz 225', 'xss name attribute'],
        'plain_text' => ['fuzz 168', 'fuzz 320', 'fuzz 341'],
        'editable' => ['fuzz 14', 'fuzz 168', 'fuzz 225', 'fuzz 320', 'fuzz 341'],
        'mentioned' => [],
        'canonical' => ['fuzz 14', 'fuzz 168', 'fuzz 225', 'fuzz 320', 'fuzz 341'],
        'to_s' => ['fuzz 118', 'fuzz 168', 'fuzz 225', 'fuzz 320', 'fuzz 341', 'xss name attribute'],
    ];

    /** @return iterable<string, array{string}> */
    public static function kinds(): iterable
    {
        foreach (array_keys(self::KNOWN_DIFFERENCES) as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('kinds')]
    public function testMatchesRails(string $kind): void
    {
        $mismatches = [];
        $details = [];
        $cases = 'canonical' === $kind || 'to_s' === $kind ? Corpus::canonical()['cases'] : Corpus::json()['cases'];
        $hosts = array_column(Corpus::json()['cases'], 'host', 'name');
        foreach ($cases as $case) {
            [$expected, $actual] = $this->outputs($kind, $case, $hosts[$case['name']] ?? null);
            if ($expected !== $actual) {
                $mismatches[] = $case['name'];
                $details[] = $case['name']."\n  expected: ".json_encode($expected, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n  actual:   ".json_encode($actual, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            }
        }
        $known = self::KNOWN_DIFFERENCES[$kind];
        $unexpected = array_values(array_diff($mismatches, $known));
        $this->assertSame([], $unexpected, \sprintf("%s: %d of %d cases differ from Rails:\n%s", $kind, \count($mismatches), \count($cases), implode("\n", array_filter($details, static fn (string $d): bool => \in_array(strtok($d, "\n"), $unexpected, true)))));
        $this->assertSame($known, array_values(array_intersect($known, $mismatches)), $kind.': a known difference now matches Rails; remove it from KNOWN_DIFFERENCES');
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return array{mixed, mixed}
     */
    private function outputs(string $kind, array $case, ?string $host): array
    {
        $context = Corpus::context($host);
        $body = $case['body'];
        $outcome = static function (callable $fn): array {
            try {
                return ['ok' => $fn()];
            } catch (RichTextError $e) {
                return ['error' => $e->getMessage()];
            }
        };

        switch ($kind) {
            case 'presentation':
                $actual = $outcome(static fn (): string => AutoLink::autoLink(TextMessagePresentationFilters::apply(Content::load($body, $context), $context)->toRenderedHtmlWithLayout($context), SafeList::autoLink()))['ok'] ?? '';
                if (isset($case['presentation']['error'])) {
                    // message_presentation's rescue raised while logging: messages/_unrenderable
                    return ['', $actual];
                }
                if (str_contains((string) $case['presentation_raised_message'], 'to_missing_attachable_partial_path')) {
                    // Deliberate: a missing attachable Rails can't find a partial for (a deleted
                    // user's mention) renders ☒ instead of blanking the message
                    return [true, str_contains($actual, '☒')];
                }

                return [Corpus::withPortDivergences($case['presentation']['ok']), $actual];
            case 'filtered':
                return [$case['filtered']['ok'] ?? null, $outcome(static fn (): string => TextMessagePresentationFilters::apply(Content::load($body, $context), $context)->toHtml())['ok'] ?? null];
            case 'plain_text':
                return [$case['plain_text']['ok'] ?? null, $outcome(static fn (): string => Content::load($body, $context)->toPlainText($context))['ok'] ?? null];
            case 'editable':
                $actual = $outcome(static fn (): ?string => Content::editableValue($body, $context));
                if (isset($case['editable']['error']) && str_contains($case['editable']['message'], 'MissingAttachable') && \array_key_exists('ok', $actual)) {
                    // Deliberate: a missing attachable leaves the editor, where Rails raises
                    return [true, true];
                }

                return [\array_key_exists('ok', $case['editable']) ? $case['editable']['ok'] : 'raises', \array_key_exists('ok', $actual) ? $actual['ok'] : 'raises'];
            case 'mentioned':
                return [$case['mentioned']['ok'] ?? 'raises', $outcome(static fn (): array => array_map(static fn ($u): int => $u->id, Content::load($body, $context)->mentionedUsers($context)))['ok'] ?? 'raises'];
            case 'canonical':
                return [$case['canonical']['ok'] ?? 'raises', $outcome(static fn (): string => Content::load($body, $context)->toHtml())['ok'] ?? 'raises'];
            case 'to_s':
                $actual = $outcome(static fn (): string => Content::load($body, $context)->toRenderedHtmlWithLayout($context))['ok'] ?? 'raises';
                if (str_contains($case['to_s']['message'] ?? '', 'missing_attachable_partial_path')) {
                    return [true, str_contains($actual, '☒')];
                }

                return [$case['to_s']['ok'] ?? 'raises', $actual];
        }

        throw new \LogicException($kind);
    }

    public function testStoredBodiesAreStableWhenStoredAgain(): void
    {
        foreach (Corpus::canonical()['cases'] as $case) {
            if (isset($case['stored_twice']['ok'], $case['canonical']['ok']) && $case['canonical']['ok'] === $case['stored_twice']['ok']) {
                $this->assertSame($case['stored_twice']['ok'], Content::load($case['canonical']['ok'], Corpus::context())->toHtml(), $case['name']);
            }
        }
    }

    public function testOpengraphUrlChecksMatchRails(): void
    {
        foreach (Corpus::json()['web_urls'] as $url) {
            try {
                $actual = ['ok' => Attachments::webUrl($url['value'], $url['host'])];
            } catch (RichTextError) {
                $actual = ['error' => true];
            }
            $this->assertSame(\array_key_exists('ok', $url['result']), \array_key_exists('ok', $actual), json_encode($url['value']).' raises in only one of Rails and the port');
            if (\array_key_exists('ok', $actual)) {
                $this->assertSame($url['result']['ok'], $actual['ok'], (string) json_encode($url['value']));
            }
        }
    }

    /** What the presentation lets through, checked on every corpus output as a browser parses it. */
    public function testPresentationsPassSecurityAssertions(): void
    {
        $violations = [];
        foreach (Corpus::json()['cases'] as $case) {
            $context = Corpus::context($case['host']);
            try {
                $html = AutoLink::autoLink(TextMessagePresentationFilters::apply(Content::load($case['body'], $context), $context)->toRenderedHtmlWithLayout($context), SafeList::autoLink());
            } catch (RichTextError) {
                continue;
            }
            foreach (self::securityViolations($html) as $violation) {
                $violations[] = $case['name'].': '.$violation;
            }
        }
        $this->assertSame([], $violations);
    }

    public function testSecurityAssertionsCatchPlantedDefects(): void
    {
        foreach ([
            '<script>alert(1)</script>', '<p onclick="x()">p</p>', "<a href=\"java\tscript:alert(1)\">x</a>", '<a href=" JAVASCRIPT:alert(1)">x</a>',
            '<span style="color: red">x</span>', '<svg><a>x</a></svg>', '<span data-controller="x">x</span>', '<details>x</details>',
        ] as $html) {
            $this->assertNotSame([], self::securityViolations($html), $html);
        }
        $this->assertSame([], self::securityViolations('<p><a target="_blank" href="https://example.com">x</a><img src="/a.png"></p>'));
    }

    /** @return list<string> */
    private static function securityViolations(string $html): array
    {
        $allowed = SafeList::autoLink();
        $violations = [];
        foreach (Html::descendants(Html::fragment($html)) as $node) {
            if (!$node instanceof Element) {
                continue;
            }
            $name = $node->localName;
            if (!$allowed->allowsTag($name) || !Html::isHtmlElement($node)) {
                $violations[] = "<$name> not in the allowlist";
            }
            foreach (Html::attributes($node) as [$attribute, $value]) {
                if (!$allowed->allowsAttribute($attribute) && 'target' !== $attribute) {
                    $violations[] = "$attribute on <$name> not in the allowlist";
                }
                $cleaned = strtolower((string) preg_replace('/[\x00-\x20\x7F]/', '', $value));
                if (\in_array($attribute, ['href', 'src', 'cite'], true) && (str_starts_with($cleaned, 'javascript:') || str_starts_with($cleaned, 'vbscript:') || str_starts_with($cleaned, 'data:text/html'))) {
                    $violations[] = "$attribute=$value on <$name>";
                }
            }
        }

        return $violations;
    }

    public function testEditorValueEncodesAttachmentsAsJson(): void
    {
        $context = Corpus::context();
        $david = Corpus::json()['users'][0];
        $value = Content::editableValue('<p>Hi <action-text-attachment sgid="'.$david['attachable_sgid'].'" content-type="application/vnd.campfire.mention"></action-text-attachment></p>', $context);
        $this->assertNotNull($value);
        $this->assertStringContainsString('content="'.htmlspecialchars(RailsJson::encode(Ruby::chomp(Attachments::renderMention(Corpus::mentionUser(1)))), \ENT_COMPAT).'"', $value);
    }
}
