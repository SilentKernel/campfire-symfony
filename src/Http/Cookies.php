<?php

declare(strict_types=1);

namespace App\Http;

use App\Rails\RailsCookies;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The request's `cookies` jar (ActionDispatch::Cookies::CookieJar) with its `signed`,
 * `encrypted` and `permanent` variants. Values are read from the raw Cookie header the way Rack
 * parses it, and written at the end of the request as Rack-formatted Set-Cookie headers.
 *
 * Options: `expires` (\DateTimeInterface), `permanent` (bool, expires in 20 years), `httponly`
 * (bool, default false), `same_site` ('lax' by default: cookies_same_site_protection), `path`
 * ('/'), `domain`.
 */
final class Cookies implements ResetInterface
{
    /** @var array<string, string>|null */
    private ?array $cookies = null;

    /** @var array<string, array{value: string, options: array<string, mixed>}> */
    private array $setCookies = [];

    /** @var array<string, array<string, mixed>> */
    private array $deleteCookies = [];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly RailsCookies $railsCookies,
        private readonly ClockInterface $clock,
    ) {
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->jar());
    }

    /** `cookies[name]`: the raw value. */
    public function get(string $name): ?string
    {
        return $this->jar()[$name] ?? null;
    }

    /** `cookies.signed[name]` */
    public function signed(string $name): mixed
    {
        return $this->railsCookies->readSigned($name, $this->get($name), $this->clock->now());
    }

    /** `cookies.encrypted[name]` */
    public function encrypted(string $name): mixed
    {
        return $this->railsCookies->readEncrypted($name, $this->get($name), $this->clock->now());
    }

    /**
     * `cookies[name] = { value:, ... }`.
     *
     * @param array<string, mixed> $options
     */
    public function set(string $name, string $value, array $options = []): void
    {
        $options = $this->handleOptions($options);

        // Rails writes the cookie only when the value changes or it carries an expiry.
        if ($this->get($name) !== $value || isset($options['expires'])) {
            $this->jar();
            $this->cookies[$name] = $value;
            $this->setCookies[$name] = ['value' => $value, 'options' => $options];
            unset($this->deleteCookies[$name]);
        }
    }

    /**
     * `cookies.signed[name] = { value:, ... }` (`cookies.signed.permanent` with `permanent: true`).
     *
     * @param array<string, mixed> $options
     */
    public function setSigned(string $name, mixed $value, array $options = []): void
    {
        $options = $this->handleOptions($options);
        $this->set($name, $this->railsCookies->writeSigned($name, $value, $options['expires'] ?? null), $options);
    }

    /** @param array<string, mixed> $options */
    public function setEncrypted(string $name, mixed $value, array $options = []): void
    {
        $options = $this->handleOptions($options);
        $this->set($name, $this->railsCookies->writeEncrypted($name, $value, $options['expires'] ?? null), $options);
    }

    /**
     * `cookies.delete(name)`: only a cookie the request sent (or this request set) is deleted.
     *
     * @param array<string, mixed> $options
     */
    public function delete(string $name, array $options = []): void
    {
        if (!$this->has($name)) {
            return;
        }

        unset($this->cookies[$name]);
        $this->deleteCookies[$name] = $this->handleOptions($options);
    }

    /**
     * The Set-Cookie headers this request produces, in Rails' order (sets, then deletes).
     *
     * @return list<RawCookie>
     */
    public function responseCookies(): array
    {
        $headers = [];
        foreach ($this->setCookies as $name => ['value' => $value, 'options' => $options]) {
            $headers[] = new RawCookie($name, RailsCookies::setCookieHeader(
                $name,
                $value,
                $options['expires'] ?? null,
                (bool) ($options['httponly'] ?? false),
                $options['same_site'],
                (bool) ($options['secure'] ?? false),
                $options['path'],
                $options['domain'] ?? null,
            ), $options['path'], $options['domain'] ?? null);
        }
        foreach ($this->deleteCookies as $name => $options) {
            $headers[] = new RawCookie($name, RailsCookies::deleteCookieHeader($name, $options['path'], $options['domain'] ?? null, $options['same_site']), $options['path'], $options['domain'] ?? null);
        }

        return $headers;
    }

    public function reset(): void
    {
        $this->cookies = null;
        $this->setCookies = [];
        $this->deleteCookies = [];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function handleOptions(array $options): array
    {
        if (true === ($options['permanent'] ?? false)) {
            $options['expires'] = RailsCookies::permanentExpiresAt($this->clock->now());
        }
        unset($options['permanent']);
        $options['path'] ??= '/';
        if (!\array_key_exists('same_site', $options)) {
            $options['same_site'] = 'lax';
        }

        return $options;
    }

    /** @return array<string, string> */
    private function jar(): array
    {
        if (null === $this->cookies) {
            $request = $this->request();
            $header = $request?->headers->get('Cookie');
            // Without a Cookie header (sub-requests, the test client), PHP's parsed cookies.
            $this->cookies = null !== $header
                ? RailsCookies::parseCookieHeader($header)
                : array_filter($request?->cookies->all() ?? [], \is_string(...));
        }

        return $this->cookies;
    }

    private function request(): ?Request
    {
        return $this->requestStack->getMainRequest();
    }
}
