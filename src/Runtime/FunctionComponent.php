<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Runtime;

use Polidog\UsePhp\Component\Defer;

use function Polidog\UsePhp\Html\getFunctionComponentName;

use Polidog\UsePhp\Storage\SnapshotPersist;
use Polidog\UsePhp\Storage\StorageType;
use Polidog\UsePhp\UsePHP;

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
 * placeholder on the page-render path, and runs the inner callable only when
 * rendering its own defer endpoint (so the same component definition serves
 * both sides of the round trip). A deferred component nested inside another
 * endpoint stays a placeholder and loads through its own endpoint.
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
        public readonly ?SnapshotPersist $persist = null,
    ) {
        if ($persist !== null && $storageType !== StorageType::Snapshot) {
            throw new \InvalidArgumentException(
                'fc(): $persist only applies to StorageType::Snapshot — client-side '
                . 'persistence mirrors the snapshot the page already carries, and '
                . $storageType->name . ' storage keeps state on the server instead.'
            );
        }
    }

    /**
     * @param array<string, mixed> $props
     */
    public function __invoke(array $props = []): Element
    {
        if ($this->defer !== null) {
            $app = RenderContext::getApp();
            if ($app === null || !$app->isDeferredEndpointTarget($this)) {
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
        $instanceKey = $this->instanceKey($props['key'] ?? null);
        unset($props['key']);

        $instanceId = RenderContext::beginComponent($componentName, $instanceKey);
        $parentState = ComponentState::current();
        $state = ComponentState::getInstance($instanceId, $this->storageType);
        ComponentState::reset();

        try {
            $result = ($this->inner)($props);
        } finally {
            RenderContext::endComponent();
            ComponentState::setCurrent($parentState);
        }

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
            if ($this->persist !== null) {
                $wrapperProps['data-usephp-persist'] = $this->persist->value;
                $restoreSignature = $app->signAction($instanceId, UsePHP::RESTORE_SIGNING_INPUT);
                if ($restoreSignature !== null) {
                    $wrapperProps['data-usephp-restore-sig'] = $restoreSignature;
                }
            }
        }

        return new Element('div', $wrapperProps, [$result]);
    }

    /**
     * The `fc()` key namespaces the props key ("row/k1"), so a component defined
     * with a fixed key can still be rendered as several independent instances.
     * With only one of the two, the id stays what it has always been, so state
     * already stored under it remains valid.
     *
     * Rendering two instances under the same id is not detected: they share one
     * slot of state.
     */
    private function instanceKey(mixed $propsKey): ?string
    {
        if ($propsKey === null) {
            return $this->key;
        }

        if (\is_int($propsKey)) {
            $propsKey = (string) $propsKey;
        }

        if (!\is_string($propsKey)) {
            throw new \InvalidArgumentException(
                'fc(): the "key" prop must be a string or int, got ' . get_debug_type($propsKey),
            );
        }

        return $this->key === null ? $propsKey : "{$this->key}/{$propsKey}";
    }
}
