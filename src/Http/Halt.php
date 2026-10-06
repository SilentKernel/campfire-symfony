<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown to stop the action with a response, like a Rails before_action that renders, redirects
 * or calls `head`. The response is sent as a normal one (not an error page).
 */
final class Halt extends \RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('Halted with '.$response->getStatusCode());
    }
}
