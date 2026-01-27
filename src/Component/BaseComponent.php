<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Component;

use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;

/**
 * Base class for usePHP components with hooks support.
 */
abstract class BaseComponent implements ComponentInterface
{
    private ?ComponentState $state = null;

    /**
     * Set the component state manager.
     * @internal
     */
    public function setComponentState(ComponentState $state): void
    {
        $this->state = $state;
    }

    /**
     * Get the component state manager.
     */
    protected function getComponentState(): ?ComponentState
    {
        return $this->state;
    }

    /**
     * React-like useState hook.
     *
     * @template T
     * @param T $initial
     * @return array{0: T, 1: callable(T): Action}
     */
    protected function useState(mixed $initial): array
    {
        if ($this->state === null) {
            return [$initial, fn(mixed $value): Action => Action::setState(0, $value)];
        }

        $index = $this->state->nextHookIndex();
        $value = $this->state->getState($index, $initial);

        $setter = function (mixed $newValue) use ($index): Action {
            return Action::setState($index, $newValue);
        };

        return [$value, $setter];
    }

    /**
     * Get the component name from the attribute or derive from class name.
     */
    public static function getComponentName(): string
    {
        $reflection = new \ReflectionClass(static::class);
        $attributes = $reflection->getAttributes(Component::class);

        if (!empty($attributes)) {
            $component = $attributes[0]->newInstance();
            if ($component->name !== null) {
                return $component->name;
            }
        }

        // Derive from class name: Counter -> counter, TodoList -> todoList
        return lcfirst($reflection->getShortName());
    }
}
