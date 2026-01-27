<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

use Polidog\UsePhp\Storage\StateStorageInterface;
use Polidog\UsePhp\Storage\StorageFactory;
use Polidog\UsePhp\Storage\StorageType;

/**
 * Manages component state using configurable storage backends.
 */
class ComponentState
{
    private static ?self $instance = null;
    private string $componentId;
    private int $hookIndex = 0;
    private StateStorageInterface $storage;

    private function __construct(string $componentId, StateStorageInterface $storage)
    {
        $this->componentId = $componentId;
        $this->storage = $storage;
    }

    public static function getInstance(string $componentId, ?StorageType $storageType = null): self
    {
        if (self::$instance === null || self::$instance->componentId !== $componentId) {
            $storage = StorageFactory::create($storageType ?? StorageType::Session);
            self::$instance = new self($componentId, $storage);
        }
        return self::$instance;
    }

    public static function current(): ?self
    {
        return self::$instance;
    }

    public static function reset(): void
    {
        if (self::$instance !== null) {
            self::$instance->hookIndex = 0;
        }
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
     */
    public function setEffectDeps(int $index, ?array $deps): void
    {
        $key = $this->getEffectDepsKey($index);
        $this->storage->set($key, $deps);
    }

    /**
     * Get stored effect dependencies.
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
}
