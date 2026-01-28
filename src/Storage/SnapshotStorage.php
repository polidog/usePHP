<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Storage;

use Polidog\UsePhp\Runtime\Snapshot;

/**
 * Snapshot-based storage - state is stored in memory during request
 * and serialized to HTML for persistence.
 *
 * This is stateless on the server side. State lives in the HTML snapshot
 * and is sent back with each request.
 */
final class SnapshotStorage implements StateStorageInterface
{
    /** @var array<string, mixed> In-memory data for current request */
    private array $data = [];

    /** @var Snapshot|null The source snapshot (if restoring from client) */
    private ?Snapshot $sourceSnapshot = null;

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

    /**
     * Initialize storage from a snapshot.
     *
     * Extracts state from the snapshot and populates the in-memory storage.
     */
    public function initializeFromSnapshot(Snapshot $snapshot, string $componentId): void
    {
        $this->sourceSnapshot = $snapshot;

        // Populate state values
        foreach ($snapshot->state as $index => $value) {
            $key = "usephp:{$componentId}:state:{$index}";
            $this->data[$key] = $value;
        }

        // Populate effect deps
        foreach ($snapshot->effectDeps as $index => $deps) {
            $key = "usephp:{$componentId}:effect_deps:{$index}";
            $this->data[$key] = $deps;
        }
    }

    /**
     * Export current state as arrays for snapshot creation.
     *
     * @param string $componentId The component ID to export state for
     * @return array{state: array<int, mixed>, effectDeps: array<int, array<mixed>|null>}
     */
    public function exportState(string $componentId): array
    {
        $state = [];
        $effectDeps = [];

        $statePrefix = "usephp:{$componentId}:state:";
        $effectDepsPrefix = "usephp:{$componentId}:effect_deps:";

        foreach ($this->data as $key => $value) {
            if (str_starts_with($key, $statePrefix)) {
                $index = (int) substr($key, strlen($statePrefix));
                $state[$index] = $value;
            } elseif (str_starts_with($key, $effectDepsPrefix)) {
                $index = (int) substr($key, strlen($effectDepsPrefix));
                $effectDeps[$index] = $value;
            }
        }

        // Sort by index to ensure consistent ordering
        ksort($state);
        ksort($effectDeps);

        return [
            'state' => $state,
            'effectDeps' => $effectDeps,
        ];
    }

    /**
     * Get the source snapshot if one was used to initialize.
     */
    public function getSourceSnapshot(): ?Snapshot
    {
        return $this->sourceSnapshot;
    }

    /**
     * Check if this storage was initialized from a snapshot.
     */
    public function hasSourceSnapshot(): bool
    {
        return $this->sourceSnapshot !== null;
    }

    /**
     * Clear all data (useful for testing or resetting state).
     */
    public function clear(): void
    {
        $this->data = [];
        $this->sourceSnapshot = null;
    }
}
