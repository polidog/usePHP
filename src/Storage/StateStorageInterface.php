<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Storage;

/**
 * Interface for state storage backends.
 */
interface StateStorageInterface
{
    /**
     * Get a value from storage.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Set a value in storage.
     */
    public function set(string $key, mixed $value): void;

    /**
     * Check if a key exists in storage.
     */
    public function has(string $key): bool;

    /**
     * Delete a key from storage.
     */
    public function delete(string $key): void;

    /**
     * Clear all keys that start with the given prefix.
     */
    public function clearByPrefix(string $prefix): void;
}
