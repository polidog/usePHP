<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

use Polidog\UsePhp\Storage\SnapshotStorage;
use Polidog\UsePhp\Storage\StateStorageInterface;
use Polidog\UsePhp\Storage\StorageFactory;
use Polidog\UsePhp\Storage\StorageType;

/**
 * Manages component state using configurable storage backends.
 */
final class ComponentState
{
    /** @var array<string, self> Instance cache for multiple component support */
    private static array $instances = [];

    /** @var self|null Current active instance */
    private static ?self $current = null;

    private string $componentId;
    private int $hookIndex = 0;
    private StateStorageInterface $storage;
    private StorageType $storageType;

    private function __construct(string $componentId, StateStorageInterface $storage, StorageType $storageType)
    {
        $this->componentId = $componentId;
        $this->storage = $storage;
        $this->storageType = $storageType;
    }

    public static function getInstance(string $componentId, ?StorageType $storageType = null): self
    {
        // Use componentId only as cache key for simpler lookup
        // This allows renderWithForm to find the correct state without knowing storageType
        if (isset(self::$instances[$componentId])) {
            self::$current = self::$instances[$componentId];
            return self::$current;
        }

        $type = $storageType ?? StorageType::Session;
        $storage = StorageFactory::create($type);
        self::$instances[$componentId] = new self($componentId, $storage, $type);

        self::$current = self::$instances[$componentId];
        return self::$current;
    }

    /**
     * Create a ComponentState from a snapshot.
     */
    public static function fromSnapshot(Snapshot $snapshot): self
    {
        $componentId = $snapshot->getInstanceId();

        $storage = StorageFactory::createSnapshotStorage();
        $storage->initializeFromSnapshot($snapshot, $componentId);

        $instance = new self($componentId, $storage, StorageType::Snapshot);
        self::$instances[$componentId] = $instance;
        self::$current = $instance;

        return $instance;
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public static function reset(): void
    {
        if (self::$current !== null) {
            self::$current->hookIndex = 0;
        }
    }

    /**
     * Clear all cached instances (useful for testing).
     */
    public static function clearInstances(): void
    {
        self::$instances = [];
        self::$current = null;
    }

    public function getComponentId(): string
    {
        return $this->componentId;
    }

    public function nextHookIndex(): int
    {
        return $this->hookIndex++;
    }

    public function getState(int $index, mixed $initial): mixed
    {
        $key = $this->getStateKey($index);

        if (!$this->storage->has($key)) {
            $this->storage->set($key, $initial);
        }

        return $this->storage->get($key);
    }

    public function setState(int $index, mixed $value): void
    {
        $key = $this->getStateKey($index);
        $this->storage->set($key, $value);
    }

    public function registerAction(string $actionId, \Closure $callback): void
    {
        $key = $this->getActionKey($actionId);
        $this->storage->set($key, $callback);
    }

    public function getAction(string $actionId): ?\Closure
    {
        $key = $this->getActionKey($actionId);
        return $this->storage->get($key);
    }

    public function clearState(): void
    {
        $this->storage->clearByPrefix("usephp:{$this->componentId}:");
    }


    /**
     * Determine if an effect should run based on dependency changes.
     *
     * @param int $index The hook index
     * @param array<mixed>|null $deps The current dependencies
     * @return bool True if the effect should run
     */
    public function shouldRunEffect(int $index, ?array $deps): bool
    {
        // If deps is null, always run (like React)
        if ($deps === null) {
            return true;
        }

        $key = $this->getEffectDepsKey($index);

        // First run - no previous deps stored
        if (!$this->storage->has($key)) {
            return true;
        }

        $prevDeps = $this->storage->get($key);

        // Empty deps array = only run on mount (already ran)
        if ($deps === [] && $prevDeps === []) {
            return false;
        }

        // Compare deps
        if (count($prevDeps) !== count($deps)) {
            return true;
        }

        foreach ($deps as $i => $dep) {
            if (!array_key_exists($i, $prevDeps) || $prevDeps[$i] !== $dep) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store effect dependencies.
     *
     * @param array<mixed>|null $deps
     */
    public function setEffectDeps(int $index, ?array $deps): void
    {
        $key = $this->getEffectDepsKey($index);
        $this->storage->set($key, $deps);
    }

    /**
     * Get stored effect dependencies.
     *
     * @return array<mixed>|null
     */
    public function getEffectDeps(int $index): ?array
    {
        $key = $this->getEffectDepsKey($index);
        return $this->storage->get($key);
    }

    /**
     * Store an effect cleanup function.
     */
    public function setEffectCleanup(int $index, callable $cleanup): void
    {
        $key = $this->getEffectCleanupKey($index);
        $this->storage->set($key, $cleanup);
    }

    /**
     * Run and clear the cleanup function for an effect.
     */
    public function runEffectCleanup(int $index): void
    {
        $key = $this->getEffectCleanupKey($index);
        $cleanup = $this->storage->get($key);
        if ($cleanup !== null && is_callable($cleanup)) {
            $cleanup();
            $this->storage->delete($key);
        }
    }

    private function getEffectDepsKey(int $index): string
    {
        return "usephp:{$this->componentId}:effect_deps:{$index}";
    }

    private function getEffectCleanupKey(int $index): string
    {
        return "usephp:{$this->componentId}:effect_cleanup:{$index}";
    }

    private function getStateKey(int $index): string
    {
        return "usephp:{$this->componentId}:state:{$index}";
    }

    private function getActionKey(string $actionId): string
    {
        return "usephp:{$this->componentId}:action:{$actionId}";
    }

    /**
     * Check if this state uses snapshot storage.
     */
    public function isSnapshotStorage(): bool
    {
        return $this->storageType === StorageType::Snapshot;
    }

    /**
     * Get the storage type.
     */
    public function getStorageType(): StorageType
    {
        return $this->storageType;
    }

    /**
     * Create a snapshot from the current state.
     *
     * Only works with Snapshot storage type.
     *
     * @throws \LogicException If not using snapshot storage
     */
    public function createSnapshot(): Snapshot
    {
        if (!$this->storage instanceof SnapshotStorage) {
            throw new \LogicException('createSnapshot() can only be called on snapshot storage');
        }

        $exported = $this->storage->exportState($this->componentId);

        // Parse componentId to get name and key
        $componentId = ComponentId::fromLegacy($this->componentId);

        return new Snapshot(
            $componentId->componentName,
            $componentId->key,
            $exported['state'],
            $exported['effectDeps'],
        );
    }

    /**
     * Get the underlying storage instance.
     */
    public function getStorage(): StateStorageInterface
    {
        return $this->storage;
    }
}
