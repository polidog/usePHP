<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

/**
 * Tracks component instances during a render pass.
 * Allows multiple instances of the same component to have separate state.
 */
class RenderContext
{
    private static ?self $instance = null;

    /** @var array<string, int> Component class name => instance count */
    private array $instanceCounts = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Start a new render pass. Resets all instance counters.
     */
    public static function beginRender(): void
    {
        self::getInstance()->instanceCounts = [];
    }

    /**
     * Get the next instance ID for a component.
     * If a key is provided, use that instead of auto-generated ID.
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
}
