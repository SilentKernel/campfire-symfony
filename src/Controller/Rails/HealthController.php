<?php

declare(strict_types=1);

namespace App\Controller\Rails;

use App\Http\Attribute\NotApplicationController;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rails::HealthController (railties lib/rails/health_controller.rb): 200 with a green page, or
 * JSON `{"status":"up","timestamp":…}`, negotiated like `respond_to` (actionpack
 * mime_negotiation.rb). Any other format is ActionController::UnknownFormat (406).
 */
#[NotApplicationController]
#[Route(defaults: ['_format' => null])]
final class HealthController extends AbstractController
{
    // actionpack mime_negotiation.rb BROWSER_LIKE_ACCEPTS
    private const string BROWSER_LIKE_ACCEPTS = '#,\s*\*/\*|\*/\*\s*,#';

    private const array MIME_FORMATS = [
        '*/*' => 'html',
        'text/*' => 'html',
        'text/html' => 'html',
        'application/xhtml+xml' => 'html',
        'application/*' => 'json',
        'application/json' => 'json',
        'text/x-json' => 'json',
        'application/jsonrequest' => 'json',
    ];

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    #[Route('/up.{_format}', name: 'rails_health_check', methods: ['GET'], priority: 27)]
    public function show(Request $request): Response
    {
        [$format, $vary] = $this->negotiateFormat($request);

        $response = match ($format) {
            'html' => new Response(
                '<!DOCTYPE html><html><body style="background-color: green"></body></html>',
                Response::HTTP_OK,
                ['Content-Type' => 'text/html; charset=utf-8'],
            ),
            'json' => new Response(
                json_encode(['status' => 'up', 'timestamp' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
                Response::HTTP_OK,
                ['Content-Type' => 'application/json; charset=utf-8'],
            ),
            default => throw new NotAcceptableHttpException(),
        };

        if ($vary) {
            $response->headers->set('Vary', 'Accept');
        }

        return $response;
    }

    /**
     * @return array{?string, bool} the format (html, json or null) and whether the Accept header chose it
     */
    private function negotiateFormat(Request $request): array
    {
        $format = $request->attributes->get('_format');
        if (\is_string($format)) {
            return [\in_array($format, ['html', 'json'], true) ? $format : null, false];
        }

        $accept = trim((string) $request->headers->get('Accept'));
        $xhr = $request->isXmlHttpRequest();
        if (('' !== $accept && 1 !== preg_match(self::BROWSER_LIKE_ACCEPTS, $accept)) || ($xhr && '' !== $accept)) {
            foreach ($this->parseAccept($accept) as $mime) {
                if (isset(self::MIME_FORMATS[$mime])) {
                    return [self::MIME_FORMATS[$mime], true];
                }
            }

            return [null, true];
        }

        return [$xhr ? null : 'html', false];
    }

    /**
     * Media ranges ordered by quality, ties keeping header order (Mime::Type.parse).
     *
     * @return list<string>
     */
    private function parseAccept(string $accept): array
    {
        $items = [];
        foreach (explode(',', $accept) as $index => $part) {
            $params = array_map(trim(...), explode(';', $part));
            $mime = strtolower(array_shift($params));
            if ('' === $mime) {
                continue;
            }
            $q = 1.0;
            foreach ($params as $param) {
                if (1 === preg_match('/^q\s*=\s*([0-9.]+)$/i', $param, $m)) {
                    $q = (float) $m[1];
                }
            }
            $items[] = [$mime, $q, $index];
        }
        usort($items, static fn (array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        return array_column($items, 0);
    }
}
