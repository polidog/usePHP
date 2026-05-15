<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

use Polidog\UsePhp\Component\Defer;

use function Polidog\UsePhp\Html\getFunctionComponentName;

use Polidog\UsePhp\Storage\StorageType;

/**
 * Invokable wrapper produced by {@see fc()}.
 *
 * Behaves like a callable function component, but also exposes the optional
 * {@see Defer} configuration so the framework (and the compile pipeline) can
 * discover deferred endpoints by inspecting the value a .psx file returns.
 * `usephp compile` materialises that discovery into a `deferred-manifest.php`
 * sidecar; `UsePHP::loadComponentManifest()` then auto-registers each entry,
 * so a manual `registerDeferred()` call is unnecessary in the normal flow.
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
                return $this->defer->buildPlaceholder($props);
            }
        }

        return $this->renderInline($props);
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
            if ($app === null) {
                throw new \LogicException(
                    'Cannot render a Snapshot-storage component outside of UsePHP::run() '
                    . '/ ::render() / ::renderElement() — no application context is set, '
                    . 'so the snapshot cannot be HMAC-signed. Emitting an unsigned snapshot '
                    . 'would let an attacker forge state on the round trip.'
                );
            }
            $snapshotJson = $app->getSnapshotSerializer()->serialize($snapshot);
            $wrapperProps['data-usephp-snapshot'] = $snapshotJson;
        }

        return new Element('div', $wrapperProps, [$result]);
    }
}
