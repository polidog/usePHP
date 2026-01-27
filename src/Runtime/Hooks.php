<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

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

    $setter = function (mixed $newValue) use ($index): Action {
        return Action::setState($index, $newValue);
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
 * @param callable(): (callable(): void)|null $callback The effect callback, optionally returns a cleanup function
 * @param array<mixed>|null $deps Dependency array (null = run every time, [] = only on mount)
 * @return void
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
        $cleanup = $callback();

        if (is_callable($cleanup)) {
            $componentState->setEffectCleanup($index, $cleanup);
        }

        // Store current deps for next comparison
        $componentState->setEffectDeps($index, $deps);
    }
}
