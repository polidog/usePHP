<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

use Polidog\UsePhp\Component\Defer;

use function Polidog\UsePhp\Html\getFunctionComponentName;

use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Storage\StorageType;

/**
 * Invokable wrapper produced by {@see fc()}.
 *
 * Behaves like a callable function component, but also exposes the optional
 * {@see Defer} configuration so the framework (and the compile pipeline) can
 * discover deferred endpoints by inspecting the value a .psx file returns —
 * no second manifest file required, no manual `registerDeferred()` call.
 *
 * When `$defer` is set, invoking the component emits an `H::defer(...)`
 * placeholder on the page-render path, and runs the inner callable on the
 * defer-endpoint render path (so the same component definition serves both
 * sides of the round trip).
 */
final class FunctionComponent
{
    /**
     * @param \Closure(array<string, mixed>): Element $inner
     */
    public function __construct(
        public readonly \Closure $inner,
        public readonly ?string $key = null,
        public readonly StorageType $storageType = StorageType::Session,
        public readonly ?Defer $defer = null,
    ) {}

    /**
     * @param array<string, mixed> $props
     */
    public function __invoke(array $props = []): Element
    {
        if ($this->defer !== null) {
            $app = RenderContext::getApp();
            if ($app === null || !$app->isRenderingDeferredEndpoint()) {
                return $this->emitPlaceholder($props);
            }
        }

        return $this->renderInline($props);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function emitPlaceholder(array $props): Element
    {
        \assert($this->defer !== null);

        $fallback = $props['fallback'] ?? null;
        unset($props['fallback'], $props['key']);

        if ($fallback !== null && !$fallback instanceof Element) {
            throw new \InvalidArgumentException(
                "Defer wrapper '{$this->defer->name}' expected `fallback` prop to be an Element, got "
                . \get_debug_type($fallback),
            );
        }

        // The defer endpoint receives props as URL query string, so we can only
        // forward scalars. Non-scalar props (children, callbacks, etc.) would
        // need a different transport — surface them as an error instead of
        // silently dropping them.
        /** @var array<string, scalar> $scalarProps */
        $scalarProps = [];
        foreach ($props as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (!\is_scalar($value)) {
                throw new \InvalidArgumentException(
                    "Defer wrapper '{$this->defer->name}' prop '" . (string) $key
                    . "' must be scalar (forwarded via query string); got " . \get_debug_type($value),
                );
            }
            $scalarProps[(string) $key] = $value;
        }

        return H::defer($this->defer->name, $scalarProps, $fallback);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function renderInline(array $props): Element
    {
        $componentName = getFunctionComponentName($this->inner);
        $instanceKey = $this->key ?? ($props['key'] ?? null);
        unset($props['key']);

        $instanceId = RenderContext::beginComponent($componentName, $instanceKey);
        $state = ComponentState::getInstance($instanceId, $this->storageType);
        ComponentState::reset();

        $result = ($this->inner)($props);

        RenderContext::endComponent();

        $wrapperProps = ['data-usephp' => $instanceId];

        if ($this->storageType === StorageType::Snapshot) {
            $snapshot = $state->createSnapshot();
            $app = RenderContext::getApp();
            $snapshotJson = $app !== null
                ? $app->getSnapshotSerializer()->serialize($snapshot)
                : $snapshot->toJson();
            $wrapperProps['data-usephp-snapshot'] = $snapshotJson;
        }

        return new Element('div', $wrapperProps, [$result]);
    }
}
