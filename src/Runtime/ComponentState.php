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
