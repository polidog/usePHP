<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

/**
 * Manages component state using PHP sessions.
 */
class ComponentState
{
    private static ?self $instance = null;
    private string $componentId;
    private int $hookIndex = 0;

    private function __construct(string $componentId)
    {
        $this->componentId = $componentId;
        $this->ensureSessionStarted();
    }

    public static function getInstance(string $componentId): self
    {
        if (self::$instance === null || self::$instance->componentId !== $componentId) {
            self::$instance = new self($componentId);
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

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = $initial;
        }

        return $_SESSION[$key];
    }

    public function setState(int $index, mixed $value): void
    {
        $key = $this->getStateKey($index);
        $_SESSION[$key] = $value;
    }

    public function registerAction(string $actionId, \Closure $callback): void
    {
        $key = $this->getActionKey($actionId);
        $_SESSION[$key] = $callback;
    }

    public function getAction(string $actionId): ?\Closure
    {
        $key = $this->getActionKey($actionId);
        return $_SESSION[$key] ?? null;
    }

    public function clearState(): void
    {
        foreach ($_SESSION as $key => $value) {
            if (str_starts_with($key, "usephp:{$this->componentId}:")) {
                unset($_SESSION[$key]);
            }
        }
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
        if (!isset($_SESSION[$key])) {
            return true;
        }

        $prevDeps = $_SESSION[$key];

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
        $_SESSION[$key] = $deps;
    }

    /**
     * Get stored effect dependencies.
     */
    public function getEffectDeps(int $index): ?array
    {
        $key = $this->getEffectDepsKey($index);
        return $_SESSION[$key] ?? null;
    }

    /**
     * Store an effect cleanup function.
     */
    public function setEffectCleanup(int $index, callable $cleanup): void
    {
        $key = $this->getEffectCleanupKey($index);
        $_SESSION[$key] = $cleanup;
    }

    /**
     * Run and clear the cleanup function for an effect.
     */
    public function runEffectCleanup(int $index): void
    {
        $key = $this->getEffectCleanupKey($index);
        if (isset($_SESSION[$key]) && is_callable($_SESSION[$key])) {
            $cleanup = $_SESSION[$key];
            $cleanup();
            unset($_SESSION[$key]);
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

    private function ensureSessionStarted(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
