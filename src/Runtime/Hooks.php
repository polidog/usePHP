<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

use function Polidog\UsePhp\Html\getFunctionComponentName;

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
 * @param StorageType $storageType Storage type for component state
 * @return callable(array<string, mixed>): Element
 */
function fc(callable $component, ?string $key = null, StorageType $storageType = StorageType::Session): callable
{
    return function (array $props = []) use ($component, $key, $storageType): Element {
        $componentName = getFunctionComponentName($component);
        $instanceKey = $key ?? ($props['key'] ?? null);
        unset($props['key']);

        $instanceId = RenderContext::beginComponent($componentName, $instanceKey);
        $state = ComponentState::getInstance($instanceId, $storageType);
        ComponentState::reset();

        $result = $component($props);

        RenderContext::endComponent();

        // Wrap with component container for snapshot support
        $wrapperProps = ['data-usephp' => $instanceId];

        if ($storageType === StorageType::Snapshot) {
            $snapshot = $state->createSnapshot();
            $app = RenderContext::getApp();
            $snapshotJson = $app !== null
                ? $app->getSnapshotSerializer()->serialize($snapshot)
                : $snapshot->toJson();
            $wrapperProps['data-usephp-snapshot'] = $snapshotJson;
        }

        return new Element('div', $wrapperProps, [$result]);
    };
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
