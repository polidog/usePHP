<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Component;

use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\RenderContext;

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
    public function getComponentState(): ?ComponentState
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
    #[\NoDiscard('useState returns [state, setState] tuple that must be used')]
    protected function useState(mixed $initial): array
    {
        if ($this->state === null) {
            // Fallback when no state is set - use RenderContext for componentId
            $componentId = RenderContext::currentComponentId();
            return [$initial, fn(mixed $value): Action => Action::setState(0, $value, $componentId)];
        }

        $index = $this->state->nextHookIndex();
        $value = $this->state->getState($index, $initial);
        $componentId = $this->state->getComponentId();

        $setter = function (mixed $newValue) use ($index, $componentId): Action {
            return Action::setState($index, $newValue, $componentId);
        };

        return [$value, $setter];
    }

    /**
     * Get the component name from the attribute or use FQCN.
     */
    public static function getComponentName(): string
    {
        $reflection = new \ReflectionClass(static::class);
        $attributes = $reflection->getAttributes(Component::class);

        return array_first($attributes)?->newInstance()->name ?? static::class;
    }
}
