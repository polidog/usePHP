<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Storage;

/**
 * Factory for creating storage instances.
 */
class StorageFactory
{
    /** @var array<string, StateStorageInterface> */
    private static array $instances = [];

    /**
     * Get or create a storage instance for the given type.
     */
    public static function create(StorageType $type): StateStorageInterface
    {
        $key = $type->value;

        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = match ($type) {
                StorageType::Session => new SessionStorage(),
                StorageType::Memory => new MemoryStorage(),
            };
        }

        return self::$instances[$key];
    }

    /**
     * Reset all storage instances (useful for testing).
     */
    public static function reset(): void
    {
        self::$instances = [];
    }
}
