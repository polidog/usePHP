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

    /** @var list<string> Stack of component IDs being rendered */
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
     * @return string Unique instance identifier (e.g., "Counter#0")
     */
    public static function beginComponent(string $componentName, ?string $key = null): string
    {
        $instanceId = self::nextInstanceId($componentName, $key);
        self::getInstance()->componentStack[] = $instanceId;
        return $instanceId;
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
     * Get the current component ID being rendered.
     *
     * @return string|null The current component ID, or null if not in a component
     */
    public static function currentComponentId(): ?string
    {
        $stack = self::getInstance()->componentStack;
        return empty($stack) ? null : end($stack);
    }

    /**
     * Get the parent component ID.
     *
     * @return string|null The parent component ID, or null if at root
     */
    public static function parentComponentId(): ?string
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
     * @param string $componentName The component class/name
     * @param string|null $key Optional explicit key from props
     * @return string Unique instance identifier (e.g., "Counter#0" or "Counter#my-key")
     */
    public static function nextInstanceId(string $componentName, ?string $key = null): string
    {
        if ($key !== null) {
            return $componentName . '#' . $key;
        }

        $context = self::getInstance();
        $context->instanceCounts[$componentName] ??= 0;
        $index = $context->instanceCounts[$componentName]++;

        return $componentName . '#' . $index;
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
