<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Storage;

/**
 * Factory for creating storage instances.
 */
final class StorageFactory
{
    /** @var array<string, StateStorageInterface> */
    private static array $instances = [];

    /**
     * Get or create a storage instance for the given type.
     */
    #[\NoDiscard('Storage instance must be used')]
    public static function create(StorageType $type): StateStorageInterface
    {
        $key = $type->value;

        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = match ($type) {
                StorageType::Session => new SessionStorage(),
                StorageType::Memory => new MemoryStorage(),
                StorageType::Snapshot => new SnapshotStorage(),
            };
        }

        return self::$instances[$key];
    }

    /**
     * Get or create a SnapshotStorage instance.
     *
     * This is a convenience method that provides type safety.
     */
    public static function createSnapshotStorage(): SnapshotStorage
    {
        $storage = self::create(StorageType::Snapshot);
        assert($storage instanceof SnapshotStorage);
        return $storage;
    }

    /**
     * Reset all storage instances (useful for testing).
     */
    public static function reset(): void
    {
        self::$instances = [];
    }
}
