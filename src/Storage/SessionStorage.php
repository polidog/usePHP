<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Storage;

/**
 * Session-based storage - state persists across page navigations.
 */
final class SessionStorage implements StateStorageInterface
{
    public function __construct()
    {
        $this->ensureSessionStarted();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function delete(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function clearByPrefix(string $prefix): void
    {
        foreach (array_keys($_SESSION) as $key) {
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
