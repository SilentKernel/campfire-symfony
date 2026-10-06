<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Rails' `session`: the encrypted `_campfire_session` cookie store
 * (reference/config/initializers/session_store.rb: `expire_after: 20.years`), with the lazy
 * loading and commit rules of Rack::Session::Abstract::PersistedSecure and
 * ActionDispatch::Session::CookieStore:
 *
 * - reading loads the cookie only if it carries a session id; writing always loads it (and gives
 *   it a new `SecureRandom.hex(16)` id when it has none);
 * - at the end of the request the cookie is written when the session was loaded, or when the
 *   request carried a session id at all (`expire_after` forces the update), with a fresh 20-year
 *   expiry each time.
 *
 * Keys are strings, values JSON-compatible (the cookie is JSON).
 */
final class RailsSession implements ResetInterface
{
    public const string KEY = '_campfire_session';

    /** @var array<string, mixed>|null */
    private ?array $cookieData = null;

    /** @var array<string, mixed> */
    private array $data = [];

    private bool $loaded = false;
    private ?string $id = null;
    private int $generation = 0;

    public function __construct(
        private readonly Cookies $cookies,
        private readonly ClockInterface $clock,
        private readonly Ssl $ssl,
    ) {
    }

    public function get(string $key): mixed
    {
        $this->loadForRead();

        return $this->data[$key] ?? null;
    }

    public function has(string $key): bool
    {
        $this->loadForRead();

        return \array_key_exists($key, $this->data);
    }

    public function set(string $key, mixed $value): void
    {
        $this->loadForWrite();
        $this->data[$key] = $value;
    }

    /** `session.delete(key)`: the removed value. */
    public function delete(string $key): mixed
    {
        $this->loadForWrite();
        $value = $this->data[$key] ?? null;
        unset($this->data[$key]);

        return $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $this->loadForRead();

        return $this->data;
    }

    /** The public session id, loading the session. */
    public function id(): string
    {
        $this->loadForWrite();

        return (string) $this->id;
    }

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    /** Incremented by resetSession(), so Flash and Csrf drop what they read before. */
    public function generation(): int
    {
        return $this->generation;
    }

    /** `reset_session`: an empty session under a new id. */
    public function resetSession(): void
    {
        $this->loadForWrite();
        $this->data = [];
        $this->id = self::generateSid();
        ++$this->generation;
    }

    /** Writes the cookie into the jar when Rack would (`commit_session?`). */
    public function commit(): void
    {
        if (!$this->loaded && !$this->exists()) {
            return;
        }
        $this->loadForWrite();

        $data = array_filter($this->data, static fn (mixed $value): bool => null !== $value);
        $data['session_id'] = $this->id;

        $expires = $this->clock->now()->modify('+20 years');
        $this->cookies->setEncrypted(self::KEY, $data, [
            'expires' => $expires,
            'httponly' => true,
            'same_site' => 'lax',
            // `config.session_options[:secure] = true` under force_ssl.
            'secure' => $this->ssl->enabled,
        ]);
    }

    public function reset(): void
    {
        $this->cookieData = null;
        $this->data = [];
        $this->loaded = false;
        $this->id = null;
        $this->generation = 0;
    }

    /** `SecureRandom.hex(16)` */
    public static function generateSid(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function exists(): bool
    {
        $sid = $this->cookieData()['session_id'] ?? null;

        return \is_string($sid) && '' !== $sid;
    }

    private function loadForRead(): void
    {
        if (!$this->loaded && $this->exists()) {
            $this->load();
        }
    }

    private function loadForWrite(): void
    {
        if (!$this->loaded) {
            $this->load();
        }
    }

    private function load(): void
    {
        $data = $this->cookieData();
        $sid = $data['session_id'] ?? null;
        if (!\is_string($sid) || '' === $sid) {
            $sid = self::generateSid();
            $data['session_id'] = $sid;
        }
        $this->id = $sid;
        $this->data = $data;
        $this->loaded = true;
    }

    /** @return array<string, mixed> */
    private function cookieData(): array
    {
        if (null === $this->cookieData) {
            $value = $this->cookies->encrypted(self::KEY);
            $this->cookieData = \is_array($value) && !array_is_list($value) ? $value : [];
        }

        return $this->cookieData;
    }
}
