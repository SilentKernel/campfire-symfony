<?php

declare(strict_types=1);

namespace App\Http;

use App\Rails\InvalidAuthenticityToken;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\NoResultException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * ActionDispatch::ShowExceptions with ActionDispatch::PublicExceptions, as in production
 * (`consider_all_requests_local = false`): the exception's status from
 * ExceptionWrapper.rescue_responses, then `public/<status>.html`, or the `{status:, error:}`
 * hash for JSON/XML requests, or an empty page when there is no such file.
 */
final class ErrorPages
{
    /** Rack::Utils::HTTP_STATUS_CODES where they differ from Symfony's texts. */
    private const array REASONS = [413 => 'Content Too Large', 422 => 'Unprocessable Content'];

    /** @var array<int, string|false> */
    private array $pages = [];

    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    /** `ExceptionWrapper#status_code` */
    public static function statusFor(\Throwable $exception): int
    {
        return match (true) {
            // No route matches the verb: ActionController::RoutingError.
            $exception instanceof MethodNotAllowedHttpException => 404,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof InvalidAuthenticityToken => 422,
            $exception instanceof EntityNotFoundException, $exception instanceof NoResultException => 404,
            $exception instanceof RequestExceptionInterface => 400,
            default => 500,
        };
    }

    public function render(int $status, Request $request): Response
    {
        $format = Mime::format($request);
        $type = null === $format ? 'text/html' : Mime::typeOf($format);
        $reason = self::REASONS[$status] ?? Response::$statusTexts[$status] ?? Response::$statusTexts[500];

        if ('HEAD' === $request->getMethod()) {
            return self::response($status, $type, '');
        }

        $body = match ($format) {
            'json' => \sprintf('{"status":%d,"error":%s}', $status, json_encode($reason, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)),
            'xml' => \sprintf("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<hash>\n  <status type=\"integer\">%d</status>\n  <error>%s</error>\n</hash>\n", $status, htmlspecialchars($reason, \ENT_NOQUOTES | \ENT_XML1)),
            'yaml' => \sprintf("---\n:status: %d\n:error: %s\n", $status, $reason),
            default => null,
        };
        if (null !== $body) {
            return self::response($status, $type, $body);
        }

        // ShowExceptions#pass_response when public/<status>.html doesn't exist.
        return self::response($status, 'text/html', $this->page($status) ?: '');
    }

    private function page(int $status): string|false
    {
        if (!\array_key_exists($status, $this->pages)) {
            $this->pages[$status] = false;
            foreach (["{$this->projectDir}/public/{$status}.html", "{$this->projectDir}/reference/public/{$status}.html"] as $path) {
                if (is_file($path)) {
                    $this->pages[$status] = (string) file_get_contents($path);
                    break;
                }
            }
        }

        return $this->pages[$status];
    }

    private static function response(int $status, string $type, string $body): Response
    {
        return new Response($body, $status, [
            'Content-Type' => $type.'; charset=UTF-8',
            'Content-Length' => (string) \strlen($body),
        ]);
    }
}
