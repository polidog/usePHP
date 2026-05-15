/**
 * usePHP - Minimal JS for partial page updates and deferred component fetches.
 * Falls back to full page reload if JS is disabled.
 * Supports snapshot-based state management.
 *
 * Security note: innerHTML/outerHTML are used intentionally here as the HTML
 * content comes from our own server endpoint, not from user input.
 */
(function() {
    // =====================================================================
    // Deferred fragment cache
    // =====================================================================
    //
    // Two tiers sit in front of the network:
    //
    //   L1  In-memory `Map<URL, DocumentFragment>`, per page lifetime.
    //       Always active and behaviourally identical to the original
    //       cache: partial updates that re-emit the same defer placeholder
    //       (e.g. layout chrome around a state-driven component) reuse the
    //       fragment instead of re-fetching, and a full page reload clears
    //       it. Bounded with LRU eviction (see below).
    //
    //   L2  `localStorage` keyed by URL, persisting across reloads and
    //       tabs. Strictly opt-in and decided by the component, not
    //       inferred from HTTP caching: the placeholder carries a bare
    //       `data-usephp-defer-cache` attribute when (and only when) the
    //       component sets `Defer::$localCache`. No attribute → the
    //       fragment never touches localStorage and stays L1-only, so a
    //       session-coupled component (the default) can't leak one user's
    //       content to the next on a shared terminal. There is no time
    //       expiry: a persisted entry lives until a `DEFER_CACHE_VERSION`
    //       bump or `clearDeferCache()` drops it. The endpoint's
    //       `Cache-Control` header is deliberately ignored here — it
    //       governs server/CDN caching, a separate concern.
    //
    // Read order is L1 → L2 → network. L2 is consulted only when the
    // placeholder opts in. An L2 hit is promoted into L1 so the rest of
    // the page shares one code path (cloning, LRU, nested-defer hydration)
    // regardless of where the fragment came from.
    //
    // Forced reset is exposed two ways:
    //   - `DEFER_CACHE_VERSION`: bump on deploy (or whenever a deferred
    //     component's markup contract changes). A mismatch against the
    //     value persisted in localStorage wipes the whole namespace before
    //     any cache read, so old fragments can't survive a release.
    //   - `window.usePHP.clearDeferCache([nameOrUrl])`: runtime purge of
    //     both tiers, all entries or a single defer name / placeholder URL.

    // In-memory cap. List-style pages that defer per-row content with
    // distinct query params (e.g. `<CommentDeferred id={$id} />` for many
    // rows) would otherwise grow the cache without limit. When the cap is
    // hit we drop the least-recently inserted entry — Maps preserve
    // insertion order in JS, so reinserting on hit lifts the entry to the
    // newest slot and gives us cheap LRU semantics.
    const DEFER_CACHE_MAX = 64;

    // localStorage entry cap, evicted oldest-`storedAt`-first. Independent
    // of L1 because L2 outlives the page and is shared across tabs.
    const DEFER_LS_MAX = 64;

    // Bump to hard-invalidate every persisted fragment on the next load.
    const DEFER_CACHE_VERSION = '1';

    const LS_PREFIX = 'usephp:defer:';
    // Note: the version key itself lives under LS_PREFIX so a namespace
    // sweep also clears it; callers re-stamp it afterwards.
    const LS_VERSION_KEY = LS_PREFIX + '__version__';

    const deferCache = new Map();

    function rememberDeferFragment(url, fragment) {
        if (deferCache.has(url)) {
            deferCache.delete(url);
        } else if (deferCache.size >= DEFER_CACHE_MAX) {
            const oldest = deferCache.keys().next().value;
            if (oldest !== undefined) {
                deferCache.delete(oldest);
            }
        }
        deferCache.set(url, fragment);
    }

    // --- localStorage tier ----------------------------------------------

    // Probe once. localStorage can be absent, disabled (Safari private
    // mode historically threw on write), or blocked by privacy settings.
    // When unavailable the whole L2 tier becomes a no-op and L1 still
    // serves the page exactly as before.
    const lsAvailable = (function() {
        try {
            const probe = LS_PREFIX + '__probe__';
            window.localStorage.setItem(probe, '1');
            window.localStorage.removeItem(probe);
            return true;
        } catch {
            return false;
        }
    })();

    // Collect every namespaced URL currently in localStorage. Keys are
    // gathered up front so callers can mutate storage while iterating
    // without skipping entries (removeItem shifts indices).
    function persistedUrls() {
        const urls = [];
        if (!lsAvailable) return urls;
        try {
            for (let i = 0; i < window.localStorage.length; i++) {
                const key = window.localStorage.key(i);
                if (key && key.startsWith(LS_PREFIX) && key !== LS_VERSION_KEY) {
                    urls.push(key.slice(LS_PREFIX.length));
                }
            }
        } catch {
            /* enumeration failed — treat as empty */
        }
        return urls;
    }

    function removePersisted(url) {
        if (!lsAvailable) return;
        try {
            window.localStorage.removeItem(LS_PREFIX + url);
        } catch {
            /* best effort */
        }
    }

    // Wipe the whole namespace (including the version key). The caller is
    // responsible for re-stamping the version when appropriate.
    function purgeAllPersisted() {
        if (!lsAvailable) return;
        const keys = [];
        try {
            for (let i = 0; i < window.localStorage.length; i++) {
                const key = window.localStorage.key(i);
                if (key && key.startsWith(LS_PREFIX)) {
                    keys.push(key);
                }
            }
            keys.forEach((k) => window.localStorage.removeItem(k));
        } catch {
            /* best effort */
        }
    }

    function stampVersion() {
        if (!lsAvailable) return;
        try {
            window.localStorage.setItem(LS_VERSION_KEY, DEFER_CACHE_VERSION);
        } catch {
            /* best effort */
        }
    }

    // Drop everything if the persisted version doesn't match this build.
    // Runs before any cache read so a stale release can't be served.
    function reconcileVersion() {
        if (!lsAvailable) return;
        let stored = null;
        try {
            stored = window.localStorage.getItem(LS_VERSION_KEY);
        } catch {
            return;
        }
        if (stored !== DEFER_CACHE_VERSION) {
            purgeAllPersisted();
            stampVersion();
        }
    }

    // Whether the component opted into client persistence. True iff the
    // placeholder carries the bare `data-usephp-defer-cache` attribute
    // (rendered when `Defer::$localCache` is set). When false, L2 is
    // skipped entirely for this placeholder (no read, no write). This is
    // the single source of truth; the HTTP response is never inspected.
    function placeholderWantsLocalCache(placeholder) {
        return placeholder.hasAttribute('data-usephp-defer-cache');
    }

    // Evict oldest-`storedAt`-first until under the cap, so a long-lived
    // page that keeps deferring fresh URLs can't grow localStorage
    // unbounded. This is a storage bound, not a validity policy — entries
    // never expire by time.
    function enforcePersistedCap() {
        if (!lsAvailable) return;
        const entries = [];
        for (const url of persistedUrls()) {
            let raw;
            try {
                raw = window.localStorage.getItem(LS_PREFIX + url);
            } catch {
                continue;
            }
            if (!raw) continue;
            let rec;
            try {
                rec = JSON.parse(raw);
            } catch {
                removePersisted(url);
                continue;
            }
            if (!rec || typeof rec.html !== 'string') {
                removePersisted(url);
                continue;
            }
            entries.push({ url, storedAt: rec.storedAt || 0 });
        }
        if (entries.length < DEFER_LS_MAX) return;
        entries.sort((a, b) => a.storedAt - b.storedAt);
        const overflow = entries.length - DEFER_LS_MAX + 1;
        for (let i = 0; i < overflow; i++) {
            removePersisted(entries[i].url);
        }
    }

    function persistFragment(url, html) {
        if (!lsAvailable) return;
        const record = JSON.stringify({
            html,
            storedAt: Date.now(),
        });
        try {
            enforcePersistedCap();
            window.localStorage.setItem(LS_PREFIX + url, record);
        } catch {
            // Quota exceeded or storage went away mid-session. Reclaim
            // space and retry once; if it still fails, give up silently —
            // L1 keeps serving this page.
            try {
                enforcePersistedCap();
                window.localStorage.setItem(LS_PREFIX + url, record);
            } catch {
                /* persistence unavailable; not fatal */
            }
        }
    }

    // Return a fresh DocumentFragment for a persisted entry, or null on
    // miss / corruption (pruning the bad entry). No time check — entries
    // are valid until a version bump or clearDeferCache() removes them.
    function readPersisted(url) {
        if (!lsAvailable) return null;
        let raw;
        try {
            raw = window.localStorage.getItem(LS_PREFIX + url);
        } catch {
            return null;
        }
        if (!raw) return null;
        let rec;
        try {
            rec = JSON.parse(raw);
        } catch {
            removePersisted(url);
            return null;
        }
        if (!rec || typeof rec.html !== 'string') {
            removePersisted(url);
            return null;
        }
        const template = document.createElement('template');
        // Server-rendered HTML is trusted content from our endpoint.
        template.innerHTML = rec.html;
        return template.content;
    }

    // --- forced reset API ------------------------------------------------

    // Match a cached URL against a registered defer name by comparing the
    // last path segment, query string ignored. Prefix-agnostic so a custom
    // server-side setDeferPrefix() (e.g. '/api/_d') still resolves.
    function urlMatchesName(url, name) {
        let path = url;
        const q = path.indexOf('?');
        if (q !== -1) path = path.slice(0, q);
        const segments = path.split('/').filter(Boolean);
        const last = segments.length ? segments[segments.length - 1] : '';
        try {
            return decodeURIComponent(last) === name;
        } catch {
            return last === name;
        }
    }

    // Purge cached defer fragments from both tiers.
    //   clearDeferCache()               → everything
    //   clearDeferCache(placeholderUrl) → that exact URL
    //   clearDeferCache(deferName)      → every variant of that name
    //
    // A bare argument is matched as an exact URL first, then falls back to
    // a defer-name match, so either form works without the caller knowing
    // the configured defer prefix.
    function clearDeferCache(target) {
        if (target === undefined || target === null || target === '') {
            deferCache.clear();
            purgeAllPersisted();
            stampVersion();
            return;
        }
        const matches = (url) => url === target || urlMatchesName(url, target);
        for (const key of Array.from(deferCache.keys())) {
            if (matches(key)) deferCache.delete(key);
        }
        for (const url of persistedUrls()) {
            if (matches(url)) removePersisted(url);
        }
    }

    const usePHP = (window.usePHP = window.usePHP || {});
    usePHP.clearDeferCache = clearDeferCache;
    usePHP.DEFER_CACHE_VERSION = DEFER_CACHE_VERSION;

    // =====================================================================
    // Partial form submission
    // =====================================================================

    document.addEventListener('submit', async function(e) {
        const form = e.target;
        if (!form.matches('[data-usephp-form]')) return;

        e.preventDefault();

        const component = form.closest('[data-usephp]');
        if (!component) {
            form.submit();
            return;
        }

        component.setAttribute('aria-busy', 'true');

        try {
            // Get current snapshot from component if using snapshot storage
            const formData = new FormData(form);
            const currentSnapshot = component.dataset.usephpSnapshot;
            if (currentSnapshot && !formData.has('_usephp_snapshot')) {
                formData.set('_usephp_snapshot', currentSnapshot);
            }

            const response = await fetch(location.href, {
                method: 'POST',
                headers: { 'X-UsePHP-Partial': '1' },
                body: formData
            });

            // If redirected, the server doesn't support partial updates for this component
            // Fall back to page navigation (PRG pattern)
            if (response.redirected) {
                location.href = response.url;
                return;
            }

            if (response.ok) {
                const html = await response.text();
                // Server-rendered HTML is trusted content from our endpoint
                component.innerHTML = html;

                // Update snapshot on component from hidden field in response
                const snapshotField = component.querySelector('[data-usephp-snapshot-update]');
                if (snapshotField) {
                    component.dataset.usephpSnapshot = snapshotField.value;
                    // Remove the hidden field as it's not needed in DOM
                    snapshotField.remove();
                }

                // Newly injected HTML may contain deferred placeholders.
                processDeferred(component);
            } else {
                form.submit();
            }
        } catch {
            form.submit();
        } finally {
            component.removeAttribute('aria-busy');
        }
    });

    // =====================================================================
    // Deferred component hydration
    // =====================================================================

    async function fetchDeferred(placeholder) {
        const url = placeholder.dataset.usephpDeferUrl;
        if (!url) return;

        // Component-declared opt-in. false → this component did not opt
        // into localStorage persistence, so L2 is bypassed for both reads
        // and writes and behaviour matches the old L1-only cache.
        const wantsLocalCache = placeholderWantsLocalCache(placeholder);

        // L1 hit: skip the network round-trip and reuse the previously
        // fetched fragment. Clone so each placeholder gets its own nodes,
        // and re-run processDeferred so nested placeholders (which the
        // cached fragment still carries) hydrate too — they will normally
        // also hit a cache by their own URL.
        const cached = deferCache.get(url);
        if (cached) {
            // Lift the entry to the newest slot so the LRU eviction sees it
            // as recently used. Cloning is needed so the cached copy stays
            // pristine for future hits.
            rememberDeferFragment(url, cached);
            const clone = cached.cloneNode(true);
            processDeferred(clone);
            placeholder.replaceWith(clone);
            return;
        }

        // L2 hit: only when this component opted in. A previous page/tab
        // persisted this fragment. Promote it into L1 (pristine clone) so
        // subsequent same-page hits and LRU bookkeeping go through the
        // shared in-memory path.
        if (wantsLocalCache) {
            const persisted = readPersisted(url);
            if (persisted) {
                rememberDeferFragment(url, persisted.cloneNode(true));
                processDeferred(persisted);
                placeholder.replaceWith(persisted);
                return;
            }
        }

        placeholder.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(url, {
                method: 'GET',
                headers: { 'X-UsePHP-Defer': '1' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                // Log so misconfiguration (unregistered name, wrong prefix,
                // etc.) is discoverable — otherwise the user just sees the
                // skeleton stay forever.
                console.warn(
                    '[usePHP] defer fetch returned non-OK status:',
                    response.status,
                    response.statusText,
                    url,
                );
                return;
            }

            const html = await response.text();
            // Server-rendered HTML is trusted content from our endpoint.
            const template = document.createElement('template');
            template.innerHTML = html;

            // Store a pristine clone in L1 before the original fragment is
            // consumed by replaceWith — moving children into the DOM would
            // leave the cached entry empty otherwise.
            rememberDeferFragment(url, template.content.cloneNode(true));

            // Persist to L2 only when the component opted in via
            // `Defer::$localCache`. The HTTP response (Cache-Control or
            // otherwise) is intentionally not consulted for this decision.
            if (wantsLocalCache) {
                persistFragment(url, html);
            }

            // A deferred component's rendered output may itself contain
            // nested defer placeholders. Kick off their fetches before
            // moving the fragment into the DOM so they hydrate too.
            processDeferred(template.content);
            placeholder.replaceWith(template.content);
        } catch (error) {
            // Network/other error — leave the fallback in place but log so
            // the failure mode is visible to developers.
            console.warn('[usePHP] defer fetch failed:', error, url);
        }
    }

    function processDeferred(root) {
        const scope = root || document;
        scope.querySelectorAll('[data-usephp-defer-url]').forEach(fetchDeferred);
    }

    // Reconcile the persisted-cache version before the first read so a
    // stale release is dropped rather than served.
    reconcileVersion();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => processDeferred());
    } else {
        processDeferred();
    }
})();
