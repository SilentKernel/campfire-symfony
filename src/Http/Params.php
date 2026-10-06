<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Exception\InvalidParameter;
use App\Http\Exception\ParameterMissing;
use App\Rails\RailsJson;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Rails' `params` (ActionDispatch::Http::Parameters): the request body (form, multipart or JSON)
 * merged with the query string, then the path parameters. Query strings and url-encoded bodies
 * are parsed like ActionDispatch::ParamBuilder (nested brackets, `a[]`, `a[][b]`, no PHP key
 * mangling), JSON bodies like the JSON params parser (a non-object body lands in "_json").
 *
 * Values are strings, null (a key without "="), lists, nested arrays or UploadedFile.
 */
final readonly class Params
{
    private const string ATTRIBUTE = '_campfire_params';
    private const int DEPTH_LIMIT = 100;

    /** @param array<string, mixed> $params */
    public function __construct(private array $params)
    {
    }

    /** The request's params, built once per request. */
    public static function fromRequest(Request $request): self
    {
        $params = $request->attributes->get(self::ATTRIBUTE);
        if ($params instanceof self) {
            return $params;
        }

        $params = new self(array_replace(
            self::requestParameters($request),
            self::parseQuery($request->server->get('QUERY_STRING') ?? ''),
            self::pathParameters($request),
        ));
        $request->attributes->set(self::ATTRIBUTE, $params);

        return $params;
    }

    /** `params[key]`, or a dotted path into nested params (`get('message.body')`). */
    public function get(string $path, mixed $default = null): mixed
    {
        if (\array_key_exists($path, $this->params)) {
            return $this->params[$path];
        }

        $value = $this->params;
        foreach (explode('.', $path) as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->params);
    }

    /** `params[key]` when it is a string, else null. */
    public function string(string $path): ?string
    {
        $value = $this->get($path);

        return \is_string($value) ? $value : null;
    }

    /**
     * `params.fetch(key, default)`: unlike get(), a missing key without a default raises
     * ParameterMissing (400).
     */
    public function fetch(string $key, mixed ...$default): mixed
    {
        if (\array_key_exists($key, $this->params)) {
            return self::wrap($this->params[$key]);
        }
        if ([] !== $default) {
            return self::wrap($default[array_key_first($default)]);
        }

        throw new ParameterMissing($key);
    }

    /**
     * `params.require(key)`: the value (Params for a hash) when present, else ParameterMissing
     * (400). Blank values (nil, "", whitespace, [], {}) count as missing; false does not.
     */
    public function require(string $key): mixed
    {
        $value = $this->params[$key] ?? null;
        if (self::isBlank($value)) {
            throw new ParameterMissing($key);
        }

        return self::wrap($value);
    }

    /**
     * `params.permit(*keys)`: the permitted scalar keys; `['key' => []]` permits a list of
     * scalars, `['key' => ['a', 'b']]` a nested hash.
     *
     * @param string|array<string, mixed> ...$filters
     *
     * @return array<string, mixed>
     */
    public function permit(string|array ...$filters): array
    {
        return self::permitIn($this->params, $filters);
    }

    /**
     * `params.expect(key: [...])` (Rails 8): require + permit, a 400 when the shape is wrong.
     *
     * @param list<string|array<string, mixed>> $filters
     *
     * @return array<string, mixed>
     */
    public function expect(string $key, array $filters): array
    {
        $value = $this->params[$key] ?? null;
        if (!\is_array($value) || array_is_list($value)) {
            throw new ParameterMissing($key);
        }

        return self::permitIn($value, $filters);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->params;
    }

    /**
     * Rack-style nested query parsing (ActionDispatch::QueryParser + ParamBuilder).
     *
     * @return array<string, mixed>
     */
    public static function parseQuery(string $query): array
    {
        $params = [];
        foreach (preg_split('/& */', $query) ?: [] as $part) {
            if ('' === $part) {
                continue;
            }
            $pair = explode('=', $part, 2);
            $params = self::storeNested($params, self::decode($pair[0]), isset($pair[1]) ? self::decode($pair[1]) : null, 0) ?? $params;
        }

        return $params;
    }

    /** `blank?` */
    public static function isBlank(mixed $value): bool
    {
        return null === $value
            || (\is_string($value) && 1 === preg_match('/\A[[:space:]]*\z/u', $value))
            || [] === $value
            || ($value instanceof self && [] === $value->params);
    }

    private static function wrap(mixed $value): mixed
    {
        return \is_array($value) && !array_is_list($value) ? new self($value) : $value;
    }

    /**
     * @param array<array-key, mixed>                                $params
     * @param list<string|array<string, mixed>>|array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    private static function permitIn(array $params, array $filters): array
    {
        $permitted = [];
        foreach ($filters as $index => $filter) {
            if (\is_string($index)) {
                $filter = [$index => $filter];
            }
            if (\is_string($filter)) {
                if (\array_key_exists($filter, $params) && self::isPermittedScalar($params[$filter])) {
                    $permitted[$filter] = $params[$filter];
                }
                continue;
            }
            foreach ($filter as $key => $nested) {
                $value = $params[$key] ?? null;
                if (!\is_array($value)) {
                    continue;
                }
                if ([] === $nested) {
                    if (array_is_list($value)) {
                        $permitted[$key] = array_values(array_filter($value, self::isPermittedScalar(...)));
                    }
                } elseif (array_is_list($value)) {
                    $permitted[$key] = array_map(static fn (mixed $item): array => \is_array($item) ? self::permitIn($item, $nested) : [], array_values(array_filter($value, \is_array(...))));
                } else {
                    $permitted[$key] = self::permitIn($value, $nested);
                }
            }
        }

        return $permitted;
    }

    private static function isPermittedScalar(mixed $value): bool
    {
        return null === $value || \is_scalar($value) || $value instanceof UploadedFile;
    }

    /** @return array<string, mixed> */
    private static function requestParameters(Request $request): array
    {
        $contentType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));

        if (\in_array($contentType, ['application/json', 'text/x-json', 'application/jsonrequest', 'application/problem+json'], true)) {
            $body = $request->getContent();
            if ('' === trim($body)) {
                return [];
            }
            try {
                $data = RailsJson::decode($body);
            } catch (\JsonException $e) {
                throw new InvalidParameter('Error occurred while parsing request parameters', $e);
            }

            return \is_array($data) && (!array_is_list($data) || [] === $data) ? $data : ['_json' => $data];
        }

        if ('application/x-www-form-urlencoded' === $contentType) {
            $body = $request->getContent();
            if ('' !== $body) {
                return self::parseQuery($body);
            }
        }

        // multipart (PHP parsed it) or a request built without a raw body (sub-requests, tests).
        $params = $request->request->all();
        foreach ($request->files->all() as $key => $file) {
            $params[$key] = self::mergeFiles($params[$key] ?? null, $file);
        }

        return $params;
    }

    private static function mergeFiles(mixed $params, mixed $files): mixed
    {
        if (!\is_array($files)) {
            return $files ?? $params;
        }
        $params = \is_array($params) ? $params : [];
        foreach ($files as $key => $file) {
            $params[$key] = self::mergeFiles($params[$key] ?? null, $file);
        }

        return $params;
    }

    /** @return array<string, mixed> */
    private static function pathParameters(Request $request): array
    {
        $params = [];
        foreach ($request->attributes->get('_route_params', []) as $key => $value) {
            if ('_format' === $key) {
                if (null !== $value && '' !== $value) {
                    $params['format'] = $value;
                }
            } elseif (!str_starts_with((string) $key, '_')) {
                $params[(string) $key] = $value;
            }
        }

        return $params;
    }

    private static function decode(string $component): string
    {
        // URI.decode_www_form_component raises on a malformed escape.
        if (1 === preg_match('/%(?![0-9a-fA-F]{2})/', $component)) {
            throw new InvalidParameter(\sprintf('invalid %%-encoding (%s)', $component));
        }
        $decoded = urldecode($component);
        if (!mb_check_encoding($decoded, 'UTF-8')) {
            throw new InvalidParameter('Invalid encoding for parameter');
        }

        return $decoded;
    }

    /**
     * ActionDispatch::ParamBuilder#store_nested_param.
     *
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>|list<mixed>|null
     */
    private static function storeNested(array $params, string $name, ?string $value, int $depth): ?array
    {
        if ($depth >= self::DEPTH_LIMIT) {
            throw new InvalidParameter('Parameters nested too deep');
        }

        if (0 === $depth) {
            $start = \strlen($name) > 1 ? strpos($name, '[', 1) : false;
            [$k, $after] = false !== $start ? [substr($name, 0, $start), substr($name, $start)] : [$name, ''];
        } elseif (str_starts_with($name, '[]')) {
            [$k, $after] = ['[]', substr($name, 2)];
        } elseif (str_starts_with($name, '[') && false !== ($start = strpos($name, ']', 1))) {
            [$k, $after] = [substr($name, 1, $start - 1), substr($name, $start + 1)];
        } else {
            [$k, $after] = [$name, ''];
        }

        if ('' === $k) {
            return 0 === $depth ? $params : null;
        }

        if ('' === $after) {
            if ('[]' === $k && 0 !== $depth) {
                return null !== $value ? [$value] : [];
            }
            $params[$k] = $value;
        } elseif ('[' === $after) {
            $params[$name] = $value;
        } elseif ('[]' === $after) {
            $params[$k] ??= [];
            if (!\is_array($params[$k]) || !array_is_list($params[$k])) {
                throw new InvalidParameter(\sprintf("expected Array (got %s) for param `%s'", self::rubyClass($params[$k]), $k));
            }
            if (null !== $value) {
                $params[$k][] = $value;
            }
        } elseif (str_starts_with($after, '[]')) {
            $childKey = null;
            if ('[' === ($after[2] ?? '') && str_ends_with($after, ']')) {
                $candidate = substr($after, 3, \strlen($after) - 4);
                if ('' !== $candidate && !str_contains($candidate, '[') && !str_contains($candidate, ']')) {
                    $childKey = $candidate;
                }
            }
            $childKey ??= substr($after, 2);
            $params[$k] ??= [];
            if (!\is_array($params[$k]) || !array_is_list($params[$k])) {
                throw new InvalidParameter(\sprintf("expected Array (got %s) for param `%s'", self::rubyClass($params[$k]), $k));
            }
            $last = array_key_last($params[$k]);
            if (null !== $last && self::isHash($params[$k][$last]) && !self::hashHasKey($params[$k][$last], $childKey)) {
                $params[$k][$last] = self::storeNested($params[$k][$last], $childKey, $value, $depth + 1);
            } else {
                $params[$k][] = self::storeNested([], $childKey, $value, $depth + 1);
            }
        } else {
            $params[$k] ??= [];
            if (!self::isHash($params[$k])) {
                throw new InvalidParameter(\sprintf("expected Hash (got %s) for param `%s'", self::rubyClass($params[$k]), $k));
            }
            $params[$k] = self::storeNested($params[$k], $after, $value, $depth + 1);
        }

        return $params;
    }

    /** A params hash: an empty array (a new hash) or a non-list one. */
    private static function isHash(mixed $value): bool
    {
        return \is_array($value) && ([] === $value || !array_is_list($value));
    }

    /** @param array<array-key, mixed> $hash */
    private static function hashHasKey(array $hash, string $key): bool
    {
        if (str_contains($key, '[]')) {
            return false;
        }
        $current = $hash;
        foreach (preg_split('/[\[\]]+/', $key) ?: [] as $part) {
            if ('' === $part) {
                continue;
            }
            if (!\is_array($current) || !\array_key_exists($part, $current)) {
                return false;
            }
            $current = $current[$part];
        }

        return true;
    }

    private static function rubyClass(mixed $value): string
    {
        return match (true) {
            \is_string($value) => 'String',
            null === $value => 'NilClass',
            \is_array($value) => array_is_list($value) && [] !== $value ? 'Array' : 'ActiveSupport::HashWithIndifferentAccess',
            default => get_debug_type($value),
        };
    }
}
