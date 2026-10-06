<?php

declare(strict_types=1);

namespace App\RichText;

use App\RichText\Html\ParseError;
use App\RichText\Sanitizer\SafeList;
use App\RichText\Sanitizer\SafeListSanitizer;

/**
 * rails_autolink 1.1.8's `auto_link(text, html: { target: "_blank" }, sanitize_options: ...)`,
 * as `MessagesHelper#message_presentation` calls it. It works on the serialized HTML with regular
 * expressions, so the exact serialization from the earlier steps matters.
 *
 * One deliberate difference (as in the Rust port) closes a stored XSS in rails_autolink: the
 * sanitized HTML is serialized with `<` and `>` escaped in attribute values. Nokogiri leaves them
 * raw, so a URL after a `>` in a `title` looked like text to `auto_linked?`, and the
 * `<a href="...">` inserted there closed the attribute and turned the rest of its value into
 * markup. With them escaped, auto_link only ever inserts links between tags.
 */
final class AutoLink
{
    /** `AUTO_LINK_RE`. Ruby's `\s` and `\w` are ASCII-only. */
    private const string AUTO_LINK_RE = '~(?:((?i:ed2k|ftp|http|https|irc|mailto|news|gopher|nntp|telnet|webcal|xmpp|callto|feed|svn|urn|aim|rsync|tag|ssh|sftp|rtsp|afs|file):)//|(?i:www)\.[a-zA-Z0-9_])[^ \t\r\n\x0B\x0C<\x{A0}"]+~u';

    /** `AUTO_EMAIL_RE` */
    private const string AUTO_EMAIL_RE = '~(?<![a-zA-Z0-9_.!#$%&\'*/=?^`{|}\~+-])[a-zA-Z0-9_.!#$%+-]\.?[a-zA-Z0-9_.!#$%&\'*/=?^`{|}\~+-]*@[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)+~';

    private const array BRACKETS = [']' => '[', ')' => '(', '}' => '{'];

    /** @throws ParseError */
    public static function autoLink(string $text, SafeList $sanitizeOptions): string
    {
        if (Ruby::isBlank($text)) {
            return '';
        }
        $text = SafeListSanitizer::sanitize($text, $sanitizeOptions, escapeAttributeBrackets: true);

        return self::autoLinkEmailAddresses(self::autoLinkUrls($text));
    }

    /** @throws ParseError */
    private static function autoLinkUrls(string $text): string
    {
        if (!preg_match_all(self::AUTO_LINK_RE, $text, $matches, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE)) {
            return $text;
        }
        $tags = new TagIndex($text);
        $out = '';
        $last = 0;
        foreach ($matches as $match) {
            [$whole, $start] = $match[0];
            $end = $start + \strlen($whole);
            $out .= substr($text, $last, $start - $last);
            $last = $end;
            $scheme = isset($match[1]) && -1 !== $match[1][1] ? $match[1][0] : null;
            $href = $whole;
            if ($tags->autoLinked($start, $end)) {
                $out .= $href;
                continue;
            }
            // Trailing punctuation isn't part of the URL, unless it closes a bracket the URL opened
            $punctuation = [];
            while (1 === preg_match('~[^\p{L}\p{Nl}\p{M}\p{Nd}\p{Pc}/\-=;]\z~u', $href, $m)) {
                $href = substr($href, 0, -\strlen($m[0]));
                $punctuation[] = $m[0];
                $opening = self::BRACKETS[$m[0]] ?? null;
                if (null !== $opening && substr_count($href, $opening) > substr_count($href, $m[0])) {
                    $href .= array_pop($punctuation);
                    break;
                }
            }
            $trailingGt = '';
            if (str_ends_with($href, '&gt;')) {
                $href = substr($href, 0, -4);
                $trailingGt = '&gt;';
            }
            $linkText = $href;
            if (null === $scheme) {
                $href = 'http://'.$href;
            }
            $linkText = SafeListSanitizer::sanitize($linkText, SafeList::defaults());
            $href = SafeListSanitizer::sanitize($href, SafeList::defaults());
            // content_tag(:a, link_text, attrs, false): only double quotes escaped in attributes
            $out .= '<a target="_blank" href="'.str_replace('"', '&quot;', $href).'">'.$linkText.'</a>';
            // SafeBuffer#+ escapes the (unsafe) punctuation string
            $out .= Ruby::h(implode('', array_reverse($punctuation))).$trailingGt;
        }

        return $out.substr($text, $last);
    }

    /** @throws ParseError */
    private static function autoLinkEmailAddresses(string $text): string
    {
        if (!str_contains($text, '@') || !preg_match_all(self::AUTO_EMAIL_RE, $text, $matches, \PREG_OFFSET_CAPTURE)) {
            return $text;
        }
        $tags = new TagIndex($text);
        $out = '';
        $last = 0;
        foreach ($matches[0] as [$email, $start]) {
            $end = $start + \strlen($email);
            $out .= substr($text, $last, $start - $last);
            $last = $end;
            if ($tags->autoLinked($start, $end)) {
                $out .= $email;
                continue;
            }
            $sanitized = SafeListSanitizer::sanitize($email, SafeList::defaults());
            // display_text is only sanitized (and so marked safe) when sanitizing changed the address
            $display = $sanitized === $email ? Ruby::h($email) : $sanitized;
            $href = 'mailto:'.str_replace('%40', '@', rawurlencode($sanitized));
            $out .= '<a target="_blank" href="'.Ruby::h($href).'">'.$display.'</a>';
        }

        return $out.substr($text, $last);
    }
}
