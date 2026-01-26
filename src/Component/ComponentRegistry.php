<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Component;

/**
 * Registry for managing usePHP components.
 */
class ComponentRegistry
{
    /** @var array<string, class-string<ComponentInterface>> */
    private array $components = [];

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

        return $this;
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
