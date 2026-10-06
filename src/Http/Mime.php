<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Exception\UnknownFormat;
use Symfony\Component\HttpFoundation\Request;

/**
 * Rails' registered MIME types (actionpack mime_types.rb plus turbo-rails' turbo_stream) and
 * format negotiation: `Mime::Type.parse` for the Accept header, `request.formats`
 * (ActionDispatch::Http::MimeNegotiation) and `respond_to`'s `negotiate_mime`.
 *
 * Formats are symbols ('html', 'turbo_stream', 'json', ...); '*\/*' stands for Mime::ALL.
 */
final class Mime
{
    public const string ALL = '*/*';

    /** symbol => [type, synonyms, extensions], in registration order. */
    public const array TYPES = [
        'html' => ['text/html', ['application/xhtml+xml'], ['xhtml']],
        'text' => ['text/plain', [], ['txt']],
        'js' => ['text/javascript', ['application/javascript', 'application/x-javascript'], []],
        'css' => ['text/css', [], []],
        'ics' => ['text/calendar', [], []],
        'csv' => ['text/csv', [], []],
        'vcf' => ['text/vcard', [], []],
        'vtt' => ['text/vtt', [], ['vtt']],
        'md' => ['text/markdown', [], ['md', 'markdown']],
        'png' => ['image/png', [], ['png']],
        'jpeg' => ['image/jpeg', [], ['jpg', 'jpeg', 'jpe', 'pjpeg']],
        'gif' => ['image/gif', [], ['gif']],
        'bmp' => ['image/bmp', [], ['bmp']],
        'tiff' => ['image/tiff', [], ['tif', 'tiff']],
        'svg' => ['image/svg+xml', [], []],
        'webp' => ['image/webp', [], ['webp']],
        'mpeg' => ['video/mpeg', [], ['mpg', 'mpeg', 'mpe']],
        'mp3' => ['audio/mpeg', [], ['mp1', 'mp2', 'mp3']],
        'ogg' => ['audio/ogg', [], ['oga', 'ogg', 'spx', 'opus']],
        'm4a' => ['audio/aac', ['audio/mp4'], ['m4a', 'mpg4', 'aac']],
        'webm' => ['video/webm', [], ['webm']],
        'mp4' => ['video/mp4', [], ['mp4', 'm4v']],
        'otf' => ['font/otf', [], ['otf']],
        'ttf' => ['font/ttf', [], ['ttf']],
        'woff' => ['font/woff', [], ['woff']],
        'woff2' => ['font/woff2', [], ['woff2']],
        'xml' => ['application/xml', ['text/xml', 'application/x-xml'], []],
        'rss' => ['application/rss+xml', [], []],
        'atom' => ['application/atom+xml', [], []],
        'yaml' => ['application/x-yaml', ['text/yaml'], ['yml', 'yaml']],
        'multipart_form' => ['multipart/form-data', [], []],
        'url_encoded_form' => ['application/x-www-form-urlencoded', [], []],
        'json' => ['application/json', ['text/x-json', 'application/jsonrequest', 'application/problem+json'], []],
        'pdf' => ['application/pdf', [], ['pdf']],
        'zip' => ['application/zip', [], ['zip']],
        'gzip' => ['application/gzip', ['application/x-gzip'], ['gz']],
        'turbo_stream' => ['text/vnd.turbo-stream.html', [], []],
    ];

    private const string FORMATS_ATTRIBUTE = '_campfire_formats';

    /** The MIME type string of a format ("text/html"), or the wildcard itself. */
    public static function typeOf(string $format): string
    {
        return self::TYPES[$format][0] ?? $format;
    }

    /** `Mime[ext]` */
    public static function lookupByExtension(string $extension): ?string
    {
        foreach (self::TYPES as $symbol => [, , $extensions]) {
            if ($symbol === $extension || \in_array($extension, $extensions, true)) {
                return $symbol;
            }
        }

        return null;
    }

    /** `Mime::Type.lookup`: a registered type or synonym (parameters ignored), '*\/*', or null. */
    public static function lookup(string $type): ?string
    {
        $base = rtrim(explode(';', $type, 2)[0]);
        foreach ([$type, $base] as $candidate) {
            foreach (self::TYPES as $symbol => [$string, $synonyms]) {
                if ($string === $candidate || \in_array($candidate, $synonyms, true)) {
                    return $symbol;
                }
            }
        }

        return self::ALL === $base ? self::ALL : null;
    }

    /**
     * `request.formats`, memoized on the request.
     *
     * @return list<string>
     */
    public static function formats(Request $request): array
    {
        $formats = $request->attributes->get(self::FORMATS_ATTRIBUTE);
        if (\is_array($formats)) {
            return $formats;
        }

        $formats = self::computeFormats($request);
        $request->attributes->set(self::FORMATS_ATTRIBUTE, $formats);

        return $formats;
    }

    /** `request.format`: the first format, or null. */
    public static function format(Request $request): ?string
    {
        return self::formats($request)[0] ?? null;
    }

    /**
     * `request.negotiate_mime(order)`: the first acceptable format among $offered (wildcards
     * resolve to the first offered), or null.
     *
     * @param list<string> $offered
     */
    public static function negotiate(Request $request, array $offered): ?string
    {
        foreach (self::formats($request) as $format) {
            if (self::ALL === $format) {
                return $offered[0] ?? null;
            }
            if (\in_array($format, $offered, true)) {
                return $format;
            }
        }

        return \in_array(self::ALL, $offered, true) ? self::format($request) : null;
    }

    /**
     * `respond_to`: the negotiated format, or UnknownFormat (406).
     *
     * @param list<string> $offered
     */
    public static function respondTo(Request $request, array $offered): string
    {
        $format = self::negotiate($request, $offered) ?? throw new UnknownFormat();

        return self::ALL === $format ? ($offered[0] ?? 'html') : $format;
    }

    /** `request.should_apply_vary_header?`: the format came from the Accept header. */
    public static function shouldApplyVaryHeader(Request $request): bool
    {
        return !self::hasFormatParam($request) && self::validAcceptHeader($request);
    }

    /**
     * `Mime::Type.parse(accept)` keeping registered types and '*\/*'.
     *
     * @return list<string>
     */
    public static function parseAccept(string $accept): array
    {
        if (!str_contains($accept, ',')) {
            $single = trim(preg_split('/;\s*q="?/', $accept)[0] ?? '');
            if ('' === $single) {
                return [];
            }
            if (null !== $expanded = self::trailingStar($single)) {
                return $expanded;
            }
            $format = self::lookup($single);

            return null === $format ? [] : [$format];
        }

        $items = [];
        $index = 0;
        preg_match_all('/[^,\s"](?:[^,"]|"[^"]*")*/', $accept, $matches);
        foreach ($matches[0] as $item) {
            $fields = preg_split('/;\s*q="?/', $item) ?: [];
            while ([] !== $fields && '' === end($fields)) {
                array_pop($fields);
            }
            $params = trim($fields[0] ?? '');
            if ('' === $params) {
                continue;
            }
            $q = $fields[1] ?? null;
            foreach (self::trailingStar($params) ?? [$params] as $name) {
                $name = self::TYPES[$name][0] ?? $name;
                $quality = null !== $q ? self::rubyToF($q) : (self::ALL === $name ? 0.0 : 1.0);
                $items[] = ['name' => $name, 'q' => floor($quality * 100), 'index' => $index++];
            }
        }
        usort($items, static fn (array $a, array $b): int => [$b['q'], $a['index']] <=> [$a['q'], $b['index']]);
        $items = self::sortXml($items);

        $formats = [];
        foreach ($items as $item) {
            $format = self::lookup($item['name']);
            if (null !== $format && !\in_array($format, $formats, true)) {
                $formats[] = $format;
            }
        }

        return $formats;
    }

    /** @return list<string> */
    private static function computeFormats(Request $request): array
    {
        if (self::hasFormatParam($request)) {
            $format = self::lookupByExtension((string) self::formatParam($request));

            return null === $format ? [] : [$format];
        }
        if (self::validAcceptHeader($request)) {
            $accept = trim((string) $request->headers->get('Accept'));
            if ('' === $accept) {
                $contentType = strtolower(trim(preg_split('/[,;]/', (string) $request->headers->get('Content-Type'))[0] ?? ''));
                $format = '' === $contentType ? null : self::lookup($contentType);

                return null === $format ? [] : [$format];
            }

            return self::parseAccept($accept);
        }
        if (1 === preg_match('/\.(\w+)\z/', $request->getPathInfo(), $m) && null !== $format = self::lookupByExtension($m[1])) {
            return [$format];
        }

        return [$request->isXmlHttpRequest() ? 'js' : 'html'];
    }

    private static function formatParam(Request $request): mixed
    {
        $routeFormat = $request->attributes->get('_route_params', [])['_format'] ?? null;
        if (null !== $routeFormat && '' !== $routeFormat) {
            return $routeFormat;
        }

        return Params::fromRequest($request)->get('format');
    }

    private static function hasFormatParam(Request $request): bool
    {
        $format = self::formatParam($request);

        return null !== $format && '' !== $format && false !== $format;
    }

    private static function validAcceptHeader(Request $request): bool
    {
        $accept = (string) $request->headers->get('Accept');
        $present = '' !== trim($accept);

        return ($request->isXmlHttpRequest() && ($present || '' !== (string) $request->headers->get('Content-Type')))
            || ($present && 1 !== preg_match('#,\s*\*/\*|\*/\*\s*,#', $accept));
    }

    /**
     * `TRAILING_STAR_REGEXP`: text/* and application/* expand to every registered type whose
     * string or synonyms contain the prefix, in registration order.
     *
     * @return list<string>|null
     */
    private static function trailingStar(string $accept): ?array
    {
        if (1 !== preg_match('#^(text|application)/\*#', $accept, $m)) {
            return null;
        }
        $prefix = $m[1].'/';
        $formats = [];
        foreach (self::TYPES as $symbol => [$string, $synonyms]) {
            if (str_contains($string, $prefix) || [] !== array_filter($synonyms, static fn (string $s): bool => str_contains($s, $prefix))) {
                $formats[] = $symbol;
            }
        }

        return $formats;
    }

    /**
     * AcceptList#sort!'s XML handling.
     *
     * @param list<array{name: string, q: float, index: int}> $items
     *
     * @return list<array{name: string, q: float, index: int}>
     */
    private static function sortXml(array $items): array
    {
        $find = static function (array $items, string $name): ?int {
            foreach ($items as $i => $item) {
                if ($item['name'] === $name) {
                    return $i;
                }
            }

            return null;
        };
        $text = $find($items, 'text/xml');
        $app = $find($items, 'application/xml');
        if (null !== $text && null !== $app) {
            $items[$app]['q'] = max($items[$app]['q'], $items[$text]['q']);
            if ($app > $text) {
                [$items[$app], $items[$text]] = [$items[$text], $items[$app]];
                [$app, $text] = [$text, $app];
            }
            array_splice($items, $text, 1);
        } elseif (null !== $text) {
            $items[$text]['name'] = 'application/xml';
        }
        if (null !== $app) {
            $appQ = $items[$app]['q'];
            for ($i = $app, $n = \count($items); $i < $n; ++$i) {
                if ($items[$i]['q'] < $appQ) {
                    break;
                }
                if (str_ends_with($items[$i]['name'], '+xml')) {
                    [$items[$app], $items[$i]] = [$items[$i], $items[$app]];
                    $app = $i;
                }
            }
        }

        return array_values($items);
    }

    /** Ruby String#to_f, enough for q-values. */
    private static function rubyToF(string $value): float
    {
        return 1 === preg_match('/\A\s*[+-]?(\d[\d_]*)?(\.\d+)?([eE][+-]?\d+)?/', $value, $m) && '' !== $m[0] && '' !== trim($m[0], " \t\n\r\f\v+-")
            ? (float) str_replace('_', '', trim($m[0]))
            : 0.0;
    }
}
