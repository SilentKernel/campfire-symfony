<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Http\Params;
use Symfony\Component\HttpFoundation\Request;

/**
 * `params.require(key).permit(...)`: a missing or blank key is ParameterMissing (400); a scalar
 * where a hash is required raises like `String#permit` would (500).
 *
 * @phpstan-require-extends \App\Controller\ApplicationController
 */
trait PermitsParams
{
    /**
     * @param string|array<string, mixed> ...$filters
     *
     * @return array<string, mixed>
     */
    protected function requirePermitted(Request $request, string $key, string|array ...$filters): array
    {
        $required = $this->params($request)->require($key);
        if (!$required instanceof Params) {
            throw new \UnexpectedValueException(\sprintf("undefined method 'permit' for an instance of %s", \is_array($required) ? 'Array' : 'String'));
        }

        return $required->permit(...$filters);
    }
}
