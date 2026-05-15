<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Component;

use Polidog\UsePhp\Storage\StorageType;
use ReflectionClass;

/**
 * Registry for managing usePHP components.
 */
final class ComponentRegistry
{
    /** @var array<string, class-string<ComponentInterface>> */
    private array $components = [];

    /** @var array<string, StorageType> */
    private array $storageTypes = [];

    /** @var array<string, Defer> */
    private array $defers = [];

    /**
     * Register a component class.
     *
     * @param class-string<ComponentInterface> $className
     */
    public function register(string $className): self
    {
        /** @var class-string<ComponentInterface> $className */
        $name = $className::getComponentName();
        $this->components[$name] = $className;
        $this->storageTypes[$name] = $this->resolveStorageType($className);

        // Explicitly clear on re-register so a class without #[Defer] cannot
        // inherit a stale Defer from a previous class registered under the
        // same component name (register() overwrites by name).
        $defer = $this->resolveDefer($className);
        if ($defer !== null) {
            $this->defers[$name] = $defer;
        } else {
            unset($this->defers[$name]);
        }

        return $this;
    }

    /**
     * Read the `#[Defer]` attribute carried by a registered class, if any.
     * Returns null when the class isn't registered or carries no `#[Defer]`.
     */
    public function getDefer(string $name): ?Defer
    {
        return $this->defers[$name] ?? null;
    }

    /**
     * Resolve the `#[Defer]` attribute from a component class.
     *
     * @param class-string<ComponentInterface> $className
     */
    private function resolveDefer(string $className): ?Defer
    {
        $reflection = new ReflectionClass($className);
        $attributes = $reflection->getAttributes(Defer::class);

        $first = $attributes[0] ?? null;
        return $first?->newInstance();
    }

    /**
     * Get the storage type for a component.
     */
    public function getStorageType(string $name): StorageType
    {
        return $this->storageTypes[$name] ?? StorageType::Session;
    }

    /**
     * Resolve storage type from component class attributes.
     *
     * @param class-string<ComponentInterface> $className
     */
    private function resolveStorageType(string $className): StorageType
    {
        $reflection = new ReflectionClass($className);
        $attributes = $reflection->getAttributes(Component::class);

        $first = $attributes[0] ?? null;
        return $first?->newInstance()->storageType ?? StorageType::Session;
    }

    /**
     * Check if a component is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->components[$name]);
    }

    /**
     * Get a component class by name.
     *
     * @return class-string<ComponentInterface>|null
     */
    public function get(string $name): ?string
    {
        return $this->components[$name] ?? null;
    }

    /**
     * Create an instance of a component.
     */
    public function create(string $name): ?ComponentInterface
    {
        $className = $this->get($name);

        if ($className === null) {
            return null;
        }

        return new $className();
    }

    /**
     * Get all registered components.
     *
     * @return array<string, class-string<ComponentInterface>>
     */
    public function all(): array
    {
        return $this->components;
    }
}
