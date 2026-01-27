<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Component;

use Polidog\UsePhp\Storage\StorageType;
use ReflectionClass;

/**
 * Registry for managing usePHP components.
 */
class ComponentRegistry
{
    /** @var array<string, class-string<ComponentInterface>> */
    private array $components = [];

    /** @var array<string, StorageType> */
    private array $storageTypes = [];

    /**
     * Register a component class.
     *
     * @param class-string<ComponentInterface> $className
     */
    public function register(string $className): self
    {
        if (!is_subclass_of($className, ComponentInterface::class)) {
            throw new \InvalidArgumentException(
                "Class {$className} must implement " . ComponentInterface::class
            );
        }

        $name = $className::getComponentName();
        $this->components[$name] = $className;
        $this->storageTypes[$name] = $this->resolveStorageType($className);

        return $this;
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

        return array_first($attributes)?->newInstance()->storageType ?? StorageType::Session;
    }

    /**
     * Register all components from a directory.
     */
    public function autoload(string $directory, string $namespace): self
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Directory not found: {$directory}");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relativePath = str_replace($directory . '/', '', $file->getPathname());
                $relativeClass = str_replace(['/', '.php'], ['\\', ''], $relativePath);
                $className = $namespace . '\\' . $relativeClass;

                if (class_exists($className) && is_subclass_of($className, ComponentInterface::class)) {
                    $this->register($className);
                }
            }
        }

        return $this;
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
