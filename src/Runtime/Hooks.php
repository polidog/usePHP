<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

use function Polidog\UsePhp\Html\getFunctionComponentName;

/**
 * React-like useState hook that stores state server-side.
 *
 * @template T
 * @param T $initial The initial state value
 * @return array{0: T, 1: callable(T): Action} A tuple of [state, setState]
 */
#[\NoDiscard('useState returns [state, setState] tuple that must be used')]
function useState(mixed $initial): array
{
    $componentState = ComponentState::current();

    if ($componentState === null) {
        // Fallback for non-component context
        return [$initial, fn(mixed $value): Action => Action::setState(0, $value)];
    }

    $index = $componentState->nextHookIndex();
    $value = $componentState->getState($index, $initial);
    $componentId = $componentState->getComponentId();

    $setter = function (mixed $newValue) use ($index, $componentId): Action {
        return Action::setState($index, $newValue, $componentId);
    };

    return [$value, $setter];
}


/**
 * React-like useEffect hook that runs side effects.
 *
 * The effect callback is executed when:
 * - On first render (mount)
 * - When any dependency value changes
 *
 * If $deps is null, the effect runs on every render.
 * If $deps is an empty array [], the effect runs only on mount.
 *
 * @param callable $callback The effect callback, optionally returns a cleanup function
 * @param array<mixed>|null $deps Dependency array (null = run every time, [] = only on mount)
 */
function useEffect(callable $callback, ?array $deps = null): void
{
    $componentState = ComponentState::current();

    if ($componentState === null) {
        // No component context, just run the effect
        $callback();
        return;
    }

    $index = $componentState->nextHookIndex();
    $shouldRun = $componentState->shouldRunEffect($index, $deps);

    if ($shouldRun) {
        // Run any cleanup from previous effect
        $componentState->runEffectCleanup($index);

        // Run the effect and capture cleanup function if returned
        /** @var (callable(): void)|null $cleanup */
        $cleanup = $callback();

        if ($cleanup !== null) {
            $componentState->setEffectCleanup($index, $cleanup);
        }

        // Store current deps for next comparison
        $componentState->setEffectDeps($index, $deps);
    }
}

/**
 * Wrap a function component for direct invocation with useState support.
 *
 * @param callable(array<string, mixed>): Element $component
 * @param string|null $key State management key
 * @return callable(array<string, mixed>): Element
 */
function fc(callable $component, ?string $key = null): callable
{
    return function (array $props = []) use ($component, $key): Element {
        $componentName = getFunctionComponentName($component);
        $instanceKey = $key ?? ($props['key'] ?? null);
        unset($props['key']);

        $instanceId = RenderContext::beginComponent($componentName, $instanceKey);
        ComponentState::getInstance($instanceId);
        ComponentState::reset();

        $result = $component($props);

        RenderContext::endComponent();
        return $result;
    };
}
