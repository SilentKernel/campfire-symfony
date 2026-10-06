<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rails' implicit (or `render :action`) rendering of an action's template: the template exists
 * only for some formats, so a request accepting none of them is ActionController::UnknownFormat
 * (406); a rendered response carries `Vary: Accept` when the Accept header chose its format.
 *
 * @phpstan-require-extends \App\Controller\ApplicationController
 */
trait ImplicitRender
{
    /** @param array<string, mixed> $parameters */
    protected function renderHtml(Request $request, string $view, array $parameters = [], int $status = Response::HTTP_OK): Response
    {
        return $this->respondTo($request, ['html' => fn (): Response => $this->render($view, $parameters, new Response('', $status))]);
    }

    /**
     * `head status` from a controller's before_action (ensure_can_administer, verify_join_code):
     * the formats aren't set yet, so the body is typed text/html without charset.
     */
    protected function filterHead(int $status): Response
    {
        return new Response('', $status, ['Content-Type' => 'text/html']);
    }

    /** `ensure_can_administer` as a before_action: 403 unless the user can administer. */
    protected function ensureCanAdminister(): ?Response
    {
        return true === $this->currentUser()?->canAdminister() ? null : $this->filterHead(Response::HTTP_FORBIDDEN);
    }

    /** @param array<string, mixed> $parameters */
    protected function renderTurboStream(Request $request, string $view, array $parameters = []): Response
    {
        return $this->respondTo($request, ['turbo_stream' => fn (): Response => $this->turboStream($this->renderView($view, $parameters))]);
    }
}
