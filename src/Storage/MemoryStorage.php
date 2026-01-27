<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Storage;

/**
 * Memory-based storage - state is reset on each page load.
 */
final class MemoryStorage implements StateStorageInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function delete(string $key): void
    {
        unset($this->data[$key]);
    }

    public function clearByPrefix(string $prefix): void
    {
        foreach (array_keys($this->data) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->data[$key]);
            }
        }
    }
}
