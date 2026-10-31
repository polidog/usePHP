<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

use Polidog\UsePhp\Component\Defer;
use Polidog\UsePhp\Storage\SnapshotPersist;
use Polidog\UsePhp\Storage\StorageType;

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
    $storageType = $componentState->getStorageType();

    $setter = function (mixed $newValue) use ($index, $componentId, $storageType): Action {
        return Action::setState($index, $newValue, $componentId, $storageType);
    };

    return [$value, $setter];
}


/**
 * Build an action that copies submitted form fields into useState hooks.
 *
 *   [$title, $setTitle] = useState('');
 *   H::form(
 *       onSubmit: useFormAction(['title' => $setTitle]),
 *       children: [H::input(name: 'title', value: $title), H::button(children: 'Save')],
 *   );
 *
 * Works on a form's onSubmit, and on onChange/onInput of a named control for
 * its own field. The value set is what the client sent: a string of at most
 * {@see \Polidog\UsePhp\UsePHP::MAX_FORM_FIELD_BYTES} bytes, empty when the
 * field was not sent (an unchecked checkbox). Treat it as untrusted. Which
 * field goes to which hook is signed and can't be changed by the client.
 *
 * @param array<mixed> $fields Field name => setter from useState()
 * @throws \InvalidArgumentException If a name can't be a form field or a value isn't a setter.
 */
function useFormAction(array $fields): Action
{
    if ($fields === []) {
        throw new \InvalidArgumentException('useFormAction() needs at least one field.');
    }

    $map = [];
    $target = null;

    foreach ($fields as $name => $setter) {
        // Other names can't be read back from $_POST: PHP turns `tags[]` into
        // an array and renames `a.b`, and `_usephp_*` belongs to the framework.
        if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_\-]*$/', $name) !== 1
            || str_starts_with($name, '_usephp_')
        ) {
            throw new \InvalidArgumentException(sprintf(
                'useFormAction(): %s is not a usable form field name.',
                json_encode($name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ));
        }

        $action = $setter instanceof \Closure ? $setter(null) : null;
        $index = $action instanceof Action && $action->type === 'setState' ? ($action->payload['index'] ?? null) : null;
        if (!$action instanceof Action || !is_int($index)) {
            throw new \InvalidArgumentException(sprintf(
                'useFormAction(): the value for field "%s" must be a setter returned by useState().',
                $name,
            ));
        }

        if ($target !== null && $target->componentId !== $action->componentId) {
            throw new \InvalidArgumentException('useFormAction(): all setters must belong to the same component.');
        }

        $map[$name] = $index;
        $target = $action;
    }

    return Action::setFields($map, $target->componentId, $target->storageType);
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
 * A cleanup function lives only in memory for the current request. It runs
 * before the same effect re-runs within that request, and is otherwise
 * discarded when the request ends: PHP cannot carry a Closure across requests,
 * so a cleanup returned in one request never runs in a later one, and there is
 * no unmount. Do not rely on it to release anything that outlives the request.
 *
 * Only a returned Closure is treated as a cleanup. Any other return value is
 * ignored, including callable strings and arrays; use first-class callable
 * syntax (`$handle->close(...)`) to return a method.
 *
 * @param callable $callback The effect callback, optionally returns a cleanup Closure
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

        // Only a Closure counts as a cleanup. Arrow functions return whatever
        // their expression yields, so is_callable() would also accept data such
        // as `fn() => 'system'` and execute it on the next run.
        $cleanup = $callback();

        if ($cleanup instanceof \Closure) {
            $componentState->setEffectCleanup($index, $cleanup);
        }

        // Store current deps for next comparison
        $componentState->setEffectDeps($index, $deps);
    }
}

/**
 * Wrap a function component for direct invocation with useState support.
 *
 * When `$defer` is supplied, the returned wrapper emits an `H::defer(...)`
 * placeholder on the page-render path and runs the inner component on the
 * defer-endpoint render path. The `Defer` value travels on the returned
 * {@see FunctionComponent}, so `usephp compile` can pick it up and
 * auto-register the endpoint without a manual `registerDeferred()` call.
 *
 * @param callable(array<string, mixed>): Element $component
 * @param string|null $key State management key
 * @param StorageType $storageType Storage type for component state
 * @param Defer|null $defer Optional deferred-endpoint configuration
 * @param SnapshotPersist|null $persist Snapshot storage only: mirror the
 *        snapshot into Web Storage so the state survives a page reload
 *        while the server stays stateless. See {@see SnapshotPersist}.
 */
function fc(
    callable $component,
    ?string $key = null,
    StorageType $storageType = StorageType::Session,
    ?Defer $defer = null,
    ?SnapshotPersist $persist = null,
): FunctionComponent {
    $closure = $component instanceof \Closure
        ? $component
        : \Closure::fromCallable($component);

    return new FunctionComponent($closure, $key, $storageType, $defer, $persist);
}

/**
 * Hook for accessing router functionality within components.
 *
 * Returns an array with:
 * - 'currentUrl': Current request URL
 * - 'params': Route parameters from current match
 *
 * @return array{
 *     currentUrl: string,
 *     params: array<string, string>
 * }
 */
function useRouter(): array
{
    $app = RenderContext::getApp();
    if ($app === null) {
        throw new \RuntimeException('useRouter() must be called within a render context');
    }

    $router = $app->getRouter();
    $currentMatch = $app->getCurrentMatch();

    return [
        'currentUrl' => $router->getCurrentUrl(),
        'params' => $currentMatch !== null ? $currentMatch->params : [],
    ];
}
