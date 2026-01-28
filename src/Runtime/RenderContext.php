<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

/**
 * Tracks component instances during a render pass.
 * Allows multiple instances of the same component to have separate state.
 * Supports nested components through a component stack.
 */
final class RenderContext
{
    private static ?self $instance = null;

    /** @var array<string, int> Component class name => instance count (legacy, flat) */
    private array $instanceCounts = [];

    /** @var list<ComponentId> Stack of ComponentIds being rendered */
    private array $componentStack = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Start a new render pass. Resets all instance counters and stack.
     */
    public static function beginRender(): void
    {
        $context = self::getInstance();
        $context->instanceCounts = [];
        $context->componentStack = [];
    }

    /**
     * Begin rendering a component. Pushes it onto the stack.
     *
     * @param string $componentName The component class/name
     * @param string|null $key Optional explicit key from props
     * @return string Unique instance identifier (e.g., "Counter#0" for legacy format)
     */
    public static function beginComponent(string $componentName, ?string $key = null): string
    {
        $componentId = self::createComponentId($componentName, $key);
        self::getInstance()->componentStack[] = $componentId;
        return $componentId->toLegacyString();
    }

    /**
     * Begin rendering a component with new format.
     *
     * @param string $componentName The component class/name
     * @param string|null $key Optional explicit key from props
     * @return ComponentId The component identifier
     */
    public static function beginComponentWithId(string $componentName, ?string $key = null): ComponentId
    {
        $componentId = self::createComponentId($componentName, $key);
        self::getInstance()->componentStack[] = $componentId;
        return $componentId;
    }

    /**
     * End rendering the current component. Pops it from the stack.
     */
    public static function endComponent(): void
    {
        $context = self::getInstance();
        if (!empty($context->componentStack)) {
            array_pop($context->componentStack);
        }
    }

    /**
     * Get the current component ID being rendered (legacy string format).
     *
     * @return string|null The current component ID, or null if not in a component
     */
    public static function currentComponentId(): ?string
    {
        $componentId = self::currentComponent();
        return $componentId?->toLegacyString();
    }

    /**
     * Get the current ComponentId object.
     *
     * @return ComponentId|null The current ComponentId, or null if not in a component
     */
    public static function currentComponent(): ?ComponentId
    {
        $stack = self::getInstance()->componentStack;
        return empty($stack) ? null : end($stack);
    }

    /**
     * Get the parent component ID (legacy string format).
     *
     * @return string|null The parent component ID, or null if at root
     */
    public static function parentComponentId(): ?string
    {
        $componentId = self::parentComponent();
        return $componentId?->toLegacyString();
    }

    /**
     * Get the parent ComponentId object.
     *
     * @return ComponentId|null The parent ComponentId, or null if at root
     */
    public static function parentComponent(): ?ComponentId
    {
        $stack = self::getInstance()->componentStack;
        $count = count($stack);
        return $count >= 2 ? $stack[$count - 2] : null;
    }

    /**
     * Get the next instance ID for a component.
     * If a key is provided, use that instead of auto-generated ID.
     * Uses global counting to ensure unique IDs across the entire render tree.
     *
     * @deprecated Use createComponentId() instead
     *
     * @param string $componentName The component class/name
     * @param string|null $key Optional explicit key from props
     * @return string Unique instance identifier (e.g., "Counter#0" or "Counter#my-key")
     */
    public static function nextInstanceId(string $componentName, ?string $key = null): string
    {
        return self::createComponentId($componentName, $key)->toLegacyString();
    }

    /**
     * Create a ComponentId for a component.
     *
     * @param string $componentName The component class/name
     * @param string|null $key Optional explicit key
     * @return ComponentId The component identifier
     */
    public static function createComponentId(string $componentName, ?string $key = null): ComponentId
    {
        $context = self::getInstance();
        $parent = self::currentComponent();

        if ($key !== null) {
            return ComponentId::create($componentName, $key, $parent);
        }

        // Auto-generate numeric key
        $context->instanceCounts[$componentName] ??= 0;
        $index = $context->instanceCounts[$componentName]++;

        return ComponentId::createWithIndex($componentName, $index, $parent);
    }

    /**
     * Get current instance count for a component (mainly for testing).
     */
    public static function getInstanceCount(string $componentName): int
    {
        return self::getInstance()->instanceCounts[$componentName] ?? 0;
    }

    /**
     * Get the current component stack depth (mainly for testing/debugging).
     */
    public static function getStackDepth(): int
    {
        return count(self::getInstance()->componentStack);
    }
}
