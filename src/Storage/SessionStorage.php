<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Storage;

/**
 * Session-based storage - state persists across page navigations.
 *
 * The PHP session is started lazily on first access (get/set/has/delete),
 * not at construction. This keeps `new SessionStorage()` free of side
 * effects so creating a Session-typed ComponentState for a page that never
 * reads or writes state does not emit `Set-Cookie` / make the response
 * uncacheable. Every access point ensures the session is started, so a
 * returning visitor's persisted state is still loaded before the first read.
 */
final class SessionStorage implements StateStorageInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureSessionStarted();

        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureSessionStarted();
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->ensureSessionStarted();

        return isset($_SESSION[$key]);
    }

    public function delete(string $key): void
    {
        $this->ensureSessionStarted();
        unset($_SESSION[$key]);
    }

    public function clearByPrefix(string $prefix): void
    {
        $this->ensureSessionStarted();
        foreach (array_keys($_SESSION ?? []) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($_SESSION[$key]);
            }
        }
    }

    private function ensureSessionStarted(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
