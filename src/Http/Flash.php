<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Rails' `flash` (ActionDispatch::Flash::FlashHash), kept in the session under "flash" as
 * `{"discard": [], "flashes": {...}}`. What a request loads is shown once: it is discarded when
 * the request commits, unless kept. `set()` shows a message on the next request, `now()` on this
 * one only. Loaded lazily, like `request.flash`.
 */
final class Flash implements ResetInterface
{
    /** @var array<string, mixed>|null null until the flash is first used */
    private ?array $flashes = null;

    /** @var array<string, true> */
    private array $discard = [];

    private int $generation = -1;

    public function __construct(private readonly RailsSession $session)
    {
    }

    public function get(string $key): mixed
    {
        return $this->load()[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->load());
    }

    /** `flash[key] = value` */
    public function set(string $key, mixed $value): void
    {
        $this->load();
        unset($this->discard[$key]);
        $this->flashes[$key] = $value;
    }

    /** `flash.now[key] = value` */
    public function now(string $key, mixed $value): void
    {
        $this->set($key, $value);
        $this->discard[$key] = true;
    }

    /** `flash.keep(key)`, or every key. */
    public function keep(?string $key = null): void
    {
        $this->load();
        if (null === $key) {
            $this->discard = [];
        } else {
            unset($this->discard[$key]);
        }
    }

    /** `flash.discard(key)`, or every key. */
    public function discard(?string $key = null): void
    {
        foreach (null === $key ? array_keys($this->load()) : [$key] as $k) {
            $this->discard[(string) $k] = true;
        }
    }

    public function delete(string $key): void
    {
        $this->load();
        unset($this->discard[$key], $this->flashes[$key]);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->load();
    }

    public function notice(): mixed
    {
        return $this->get('notice');
    }

    public function alert(): mixed
    {
        return $this->get('alert');
    }

    /** `request.commit_flash` (actionpack flash.rb), run before the session is committed. */
    public function commit(): void
    {
        if ($this->isLoaded() && ([] !== $this->flashes || $this->session->has('flash'))) {
            $this->session->set('flash', $this->toSessionValue());
        }
        if ($this->session->isLoaded() && $this->session->has('flash') && null === $this->session->get('flash')) {
            $this->session->delete('flash');
        }
    }

    /**
     * `FlashHash#to_session_value`: what survives this request, or null.
     *
     * @return array{discard: list<string>, flashes: array<string, mixed>}|null
     */
    public function toSessionValue(): ?array
    {
        $keep = array_diff_key($this->flashes ?? [], $this->discard);

        return [] === $keep ? null : ['discard' => [], 'flashes' => $keep];
    }

    public function reset(): void
    {
        $this->flashes = null;
        $this->discard = [];
        $this->generation = -1;
    }

    private function isLoaded(): bool
    {
        return null !== $this->flashes && $this->generation === $this->session->generation();
    }

    /**
     * `FlashHash.from_session_value`: the stored flashes minus the stored discards, all marked
     * for discard at the end of this request.
     *
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if (!$this->isLoaded()) {
            $this->flashes = [];
            $this->discard = [];
            $this->generation = $this->session->generation();

            $value = $this->session->get('flash');
            if (\is_array($value) && \is_array($value['flashes'] ?? null)) {
                $flashes = $value['flashes'];
                foreach (\is_array($value['discard'] ?? null) ? $value['discard'] : [] as $key) {
                    unset($flashes[$key]);
                }
                $this->flashes = $flashes;
                $this->discard = array_fill_keys(array_map(strval(...), array_keys($flashes)), true);
            }
        }

        return $this->flashes ?? [];
    }
}
