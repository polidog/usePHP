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
