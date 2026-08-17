<?php

declare(strict_types=1);

namespace Polidog\UsePhp\Component;

use Attribute;
use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\UsePHP;

/**
 * Marks a component as a deferred endpoint.
 *
 * Doubles as:
 *   - a class attribute on `BaseComponent` subclasses (`#[Defer(name: '...')]`)
 *   - a plain value object passed to `fc(..., defer: new Defer(...))` for
 *     closure-based components in .psx files
 *
 * Carrying the registration on the component itself (instead of the call site)
 * means the framework can enumerate every deferred endpoint at compile time
 * and auto-register them — removing the manual `UsePHP::registerDeferred()`
 * wiring.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Defer
{
    /**
     * @param string $name URL-safe registration name (`[A-Za-z0-9_-]+`).
     *        Becomes the path segment under the defer prefix, so it appears
     *        in client URLs and must be unique across the application.
     * @param string|null $cacheControl Optional Cache-Control header for the
     *        defer endpoint response. Omit to fall back to the framework
     *        default (`private, max-age=0`). This governs server/CDN caching
     *        only and is intentionally decoupled from the client-side
     *        localStorage cache below.
     * @param bool $localCache Opt-in client-side (localStorage) caching.
     *        `false` (default) means usephp.js never persists this
     *        component across reloads — it stays in the per-page in-memory
     *        cache only. `true` tells the client to persist the fetched
     *        fragment; by default there is no time expiry, the entry lives
     *        until a `DEFER_CACHE_VERSION` bump or `clearDeferCache()`
     *        drops it (see {@see self::$localCacheTtl} to bound it by
     *        time). The component decides this explicitly; usephp.js does
     *        not infer it from the `Cache-Control` header.
     * @param int $localCacheTtl Optional client-side (localStorage) cache
     *        lifetime in seconds. Any value `<= 0` (the `0` default
     *        included) means no time expiry and is normalised to `0` —
     *        byte-identical behaviour to a plain `localCache: true`; a
     *        nonsensical negative is read as "no bound", not an error. A
     *        positive value bounds the persisted entry's age: once it is
     *        older than this many seconds usephp.js discards it on the
     *        next read and re-fetches from the network (the fallback shows
     *        briefly, then the fresh fragment). This is a hard discard,
     *        not stale-while-revalidate. Governs the L2 localStorage tier
     *        only — the per-page L1 in-memory cache has no time bound.
     *        Meaningless without persistence, so passing a *positive* TTL
     *        with `localCache: false` throws.
     * @param bool $reloadable Opt-in explicit reload. `false` (default)
     *        keeps the historical behaviour: usephp.js fetches the fragment
     *        once and `replaceWith()`s the placeholder away, leaving no
     *        re-targetable anchor in the DOM and producing byte-identical
     *        markup to before this option existed. `true` makes usephp.js
     *        keep the wrapper element after the fragment resolves and tag
     *        it with `data-usephp-defer-name`, so the region can be
     *        re-fetched later via `window.usePHP.reloadDefer('<name>')` or
     *        a form's `data-usephp-reload-defer` attribute (e.g. reloading
     *        a deferred list after a form mutates its data). The reload
     *        always busts both cache tiers for that URL and hits the
     *        network. Independent of {@see self::$localCache} and
     *        {@see self::$cacheControl}.
     */
    public function __construct(
        public string $name,
        public ?string $cacheControl = null,
        public bool $localCache = false,
        public bool $reloadable = false,
        public int $localCacheTtl = 0,
    ) {
        if (!UsePHP::isValidDeferName($name)) {
            throw new \InvalidArgumentException(
                'Deferred component name must match `' . UsePHP::DEFER_NAME_PATTERN . "`, got: '$name'",
            );
        }
        // A non-positive TTL just means "no time bound" — the same as the
        // 0 default — rather than an error. Normalise so $localCacheTtl
        // always reads back as its effective value (0), and so the guard
        // and renderer below only ever see a clean >= 0.
        $this->localCacheTtl = max(0, $this->localCacheTtl);
        if ($this->localCacheTtl > 0 && $localCache === false) {
            throw new \InvalidArgumentException(
                "Defer target '$name': localCacheTtl has no effect without localCache: true "
                . '(it bounds the localStorage entry, which is only written when localCache is opted in).',
            );
        }
    }

    /**
     * Build the page-side placeholder element from a wrapping component's
     * props. Pulls `fallback` out (must be an Element or null), strips
     * framework-only keys, and forwards the remaining scalar props as
     * query-string params on the eventual `/_defer/{name}` GET.
     *
     * Shared by {@see \Polidog\UsePhp\Runtime\FunctionComponent} (closure
     * wrappers from `fc(..., defer: ...)`) and the class-component PSX
     * bridge installed in `UsePHP::register()`, so the two paths can't
     * drift on what counts as a valid prop or fallback.
     *
     * @param array<string, mixed> $props
     */
    public function buildPlaceholder(array $props): Element
    {
        $fallback = $props['fallback'] ?? null;
        // `key` is a framework-level identifier for component-state
        // separation, not a render-time prop. It must not leak into the
        // query string of the defer endpoint.
        unset($props['fallback'], $props['key']);

        if ($fallback !== null && !($fallback instanceof Element)) {
            throw new \InvalidArgumentException(
                "Defer target '{$this->name}' expected `fallback` prop to be an Element, got "
                . \get_debug_type($fallback),
            );
        }

        /** @var array<string, scalar> $scalarProps */
        $scalarProps = [];
        foreach ($props as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (!\is_scalar($value)) {
                throw new \InvalidArgumentException(
                    "Defer target '{$this->name}' prop '" . (string) $key
                    . "' must be scalar (forwarded via query string); got " . \get_debug_type($value),
                );
            }
            $scalarProps[(string) $key] = $value;
        }

        return H::defer($this->name, $scalarProps, $fallback, $this->localCache, $this->reloadable, $this->localCacheTtl);
    }
}
