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
    //       content to the next on a shared terminal. By default there is
    //       no time expiry: a persisted entry lives until a
    //       `DEFER_CACHE_VERSION` bump or `clearDeferCache()` drops it. A
    //       component may additionally cap the entry's age via
    //       `Defer::$localCacheTtl`, surfaced as a separate
    //       `data-usephp-defer-cache-ttl="<seconds>"` attribute: once the
    //       persisted entry is older than that, the next read discards it
    //       and falls through to the network (a hard discard — the
    //       fallback shows briefly, then the fresh fragment — not
    //       stale-while-revalidate). No attribute → no time bound, exactly
    //       as before. The endpoint's `Cache-Control` header is
    //       deliberately ignored here — it governs server/CDN caching, a
    //       separate concern.
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
    //
    // Explicit reload is a separate, opt-in concern from cache
    // invalidation. A component that sets `Defer::$reloadable` renders a
    // placeholder carrying `data-usephp-defer-name`; usephp.js then keeps
    // that wrapper in the DOM after the fragment resolves (instead of
    // replacing it away) and swaps content *inside* it. The retained,
    // name-tagged wrapper is what makes a later re-fetch possible:
    //   - `window.usePHP.reloadDefer([nameOrUrl])` busts that URL's cache
    //     (both tiers) and re-fetches in place — e.g. after a mutation.
    //   - a form's `data-usephp-reload-defer="<names>"` does the same
    //     automatically once its partial submit succeeds (the common
    //     "form edits data, reload the deferred list" pattern).
    // Components that don't opt in are still replaced away on resolve and
    // produce byte-identical markup/behaviour to before this feature.

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

    // Per-placeholder fetch coordination, keyed by the element so entries
    // vanish with the node (WeakMap/WeakSet, no manual cleanup):
    //
    //   deferInFlight  the element currently has a fetch running. A second
    //                  generic fetch (e.g. processDeferred re-scanning after
    //                  a partial submit) is dropped — single-flight — while
    //                  a *forced* fetch (reloadDefer) is allowed to
    //                  supersede it instead of being dropped.
    //   deferGen       monotonically increasing token per fetch attempt. A
    //                  superseded (older) response checks its token before
    //                  placing and bails, so the wrapper always settles on
    //                  the newest requested state — no double placement, no
    //                  stale-then-fresh flicker.
    //   deferAbort     AbortController for the in-flight network request, so
    //                  a supersede also cancels the wasted round-trip rather
    //                  than just discarding its result.
    const deferInFlight = new WeakSet();
    const deferGen = new WeakMap();
    const deferAbort = new WeakMap();

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

    // Optional hard-discard lifetime for this placeholder's L2 entry, in
    // milliseconds. Read from `data-usephp-defer-cache-ttl` (seconds),
    // rendered only when the component set a positive `Defer::$localCacheTtl`
    // *and* opted into persistence. Returns 0 — no time bound, the historical
    // behaviour — when the attribute is absent or not a positive integer.
    // The TTL is kept on the placeholder rather than baked into the stored
    // record so the same persisted fragment stays valid for whatever the
    // current build asks, and re-tuning the TTL needs no version bump.
    function placeholderCacheTtlMs(placeholder) {
        const raw = placeholder.dataset.usephpDeferCacheTtl;
        if (!raw) return 0;
        const ttl = parseInt(raw, 10);
        return Number.isFinite(ttl) && ttl > 0 ? ttl * 1000 : 0;
    }

    // Evict oldest-`storedAt`-first until under the cap, so a long-lived
    // page that keeps deferring fresh URLs can't grow localStorage
    // unbounded. This enforces only the storage-size cap; it applies no
    // time policy. Per-placeholder age-out (`Defer::$localCacheTtl`) is a
    // separate validity check done in readPersisted() at read time, so an
    // entry that has aged out but not yet been read still counts toward
    // the cap here until that read prunes it.
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
            if (
                !rec ||
                typeof rec.html !== 'string' ||
                rec.v !== DEFER_CACHE_VERSION
            ) {
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
        // Stamp the record with this build's version. A tab still running
        // the previous build can write here *after* a newer tab purged the
        // namespace and re-stamped the version key — the key alone would
        // then mark that stale fragment as current. Carrying the version
        // per record (and rejecting mismatches on read) closes that race.
        const record = JSON.stringify({
            v: DEFER_CACHE_VERSION,
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
    // miss / corruption / version mismatch / age-out (pruning the bad
    // entry). `maxAgeMs` is the placeholder's optional hard-discard
    // lifetime: 0 (the default) means no time check — entries are valid
    // until a version bump or clearDeferCache() removes them; a positive
    // value discards the entry once `storedAt` is older than that, so the
    // caller falls through to a fresh network fetch. The per-record
    // version guards against a stale write landing from an older
    // still-open tab.
    function readPersisted(url, maxAgeMs = 0) {
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
        if (
            !rec ||
            typeof rec.html !== 'string' ||
            rec.v !== DEFER_CACHE_VERSION
        ) {
            removePersisted(url);
            return null;
        }
        // Component-declared hard discard. Treat a missing/garbled
        // storedAt as infinitely old so a positive TTL always re-fetches
        // rather than serving an entry it can't date.
        if (
            maxAgeMs > 0 &&
            Date.now() - (typeof rec.storedAt === 'number' ? rec.storedAt : 0) > maxAgeMs
        ) {
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

    // Explicitly re-fetch resolved reloadable deferred regions.
    //   reloadDefer()              → every reloadable region on the page
    //   reloadDefer(deferName)     → every region with that defer name
    //   reloadDefer(placeholderUrl)→ the region(s) at that exact URL
    //
    // Only components that opted in via `Defer::$reloadable` keep a
    // re-targetable wrapper (`data-usephp-defer-name`); non-reloadable
    // defers were replaced away on resolve and are intentionally not found
    // here. Each match has both cache tiers busted for its URL first, so
    // the reload always reflects current server state rather than a stale
    // cached fragment. Matching mirrors clearDeferCache(): exact URL or
    // defer-name, prefix-agnostic. Returns the number of regions reloaded
    // so callers can detect a no-op (e.g. a typo'd name).
    function reloadDefer(target) {
        const wrappers = document.querySelectorAll('[data-usephp-defer-name][data-usephp-defer-url]');
        const all = target === undefined || target === null || target === '';
        let count = 0;
        wrappers.forEach((wrapper) => {
            const url = wrapper.dataset.usephpDeferUrl;
            if (!url) return;
            const name = wrapper.getAttribute('data-usephp-defer-name');
            if (!all && !(name === target || url === target || urlMatchesName(url, target))) {
                return;
            }
            // A reload must reflect current server state, so drop any cached
            // fragment for this URL (both tiers) before re-fetching.
            clearDeferCache(url);
            // Re-arm: clearing the resolved marker lets fetchDeferred run
            // again (it self-guards against resolved wrappers otherwise).
            wrapper.removeAttribute('data-usephp-defer-loaded');
            wrapper.setAttribute('aria-busy', 'true');
            count++;
            // force: this is an explicit user/app request for fresh data —
            // supersede any fetch already running for this wrapper rather
            // than yielding to it (which would leave stale content).
            fetchDeferred(wrapper, { force: true });
        });
        return count;
    }

    const usePHP = (window.usePHP = window.usePHP || {});
    usePHP.clearDeferCache = clearDeferCache;
    usePHP.reloadDefer = reloadDefer;
    usePHP.DEFER_CACHE_VERSION = DEFER_CACHE_VERSION;
    // usePHP.restoreSnapshots / usePHP.forgetSnapshots are attached further
    // down, next to the client-side snapshot persistence they operate on.

    // =====================================================================
    // Declarative reload triggers
    // =====================================================================
    //
    // `data-usephp-reload-defer` is read in two complementary places, both
    // thin wrappers over reloadDefer() — the imperative API stays the one
    // source of truth:
    //
    //   • on a `<form data-usephp-form>` — fires after a successful partial
    //     submit (see the submit handler). Timing matters: the mutation's
    //     response is applied first, so the re-fetch reflects new state.
    //   • on any element *outside* a usephp form — fires on click. This is
    //     the "no form involved" path: a standalone Refresh button/link, a
    //     toolbar control, etc.
    //
    // The attribute value is a space/comma-separated list of defer names
    // (or exact URLs). An empty value reloads every reloadable region.

    function parseReloadTargets(value) {
        return (value || '').split(/[\s,]+/).filter(Boolean);
    }

    function dispatchReloadDefer(value) {
        const targets = parseReloadTargets(value);
        if (targets.length === 0) {
            reloadDefer();
            return;
        }
        targets.forEach((t) => reloadDefer(t));
    }

    // Click path: any element carrying the attribute, except forms (their
    // submit path owns the timing) and controls inside a usephp form (the
    // form's own attribute drives the post-submit reload; firing on click
    // would race the async submit and re-fetch stale data).
    document.addEventListener('click', function(e) {
        // e.target can be a non-Element (text node, document) for some
        // synthetic/edge events; Element.closest only exists on Elements,
        // so normalise first or a stray click would throw and break all JS.
        const start = e.target instanceof Element
            ? e.target
            : (e.target && e.target.parentElement) || null;
        if (!start) return;
        const trigger = start.closest('[data-usephp-reload-defer]');
        if (!trigger) return;
        if (trigger.tagName === 'FORM') return;
        if (trigger.closest('[data-usephp-form]')) return;
        // A reload link that also navigated away would be self-defeating;
        // suppress default activation for anchors.
        if (trigger.tagName === 'A') e.preventDefault();
        dispatchReloadDefer(trigger.getAttribute('data-usephp-reload-defer'));
    });

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
                applyPartial(component, await response.text());

                // Declarative post-submit reload, dispatched *before* the
                // generic processDeferred() scan. The canonical "form
                // mutates data → reload the deferred list" wiring: the form
                // names the deferred region(s) to refresh. Running it first
                // means a forced, cache-busted fetch is already in flight
                // for those wrappers, so the processDeferred() scan below
                // single-flight-yields instead of racing it with a second
                // (possibly cache-stale) fetch. The mutation's partial
                // response is already applied (innerHTML above), so the
                // re-fetch reflects the new state.
                if (form.hasAttribute('data-usephp-reload-defer')) {
                    dispatchReloadDefer(form.getAttribute('data-usephp-reload-defer'));
                }

                // Newly injected HTML may contain (other, non-reload-target)
                // deferred placeholders that still need their initial fetch.
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

    // A reloadable component renders `data-usephp-defer-name`; usephp.js
    // keys off that attribute's presence alone (mirroring the bare
    // data-usephp-defer-cache opt-in). When absent, every path below is
    // byte-for-byte the historical behaviour.
    function deferIsReloadable(el) {
        return el.hasAttribute('data-usephp-defer-name');
    }

    // Single placement point shared by the L1, L2 and network paths so the
    // reloadable / non-reloadable decision can't drift between them.
    //
    //   non-reloadable → hydrate nested placeholders in the fragment, then
    //                     replace the placeholder away (unchanged).
    //   reloadable     → swap the fragment *into* the wrapper, keeping the
    //                     name-tagged, url-bearing wrapper in the DOM so a
    //                     later reloadDefer() can re-target and re-fetch it.
    function placeDeferredFragment(placeholder, fragment) {
        if (!deferIsReloadable(placeholder)) {
            // Hydrate nested defers before the fragment enters the DOM, then
            // splice it in. Exactly the pre-feature code path.
            processDeferred(fragment);
            placeholder.replaceWith(fragment);
            return;
        }

        placeholder.replaceChildren(fragment);
        placeholder.removeAttribute('aria-busy');
        // Mark resolved so a generic processDeferred() re-scan does not
        // re-fetch this still-url-bearing wrapper; reloadDefer() clears it.
        placeholder.setAttribute('data-usephp-defer-loaded', '');
        // Nested placeholders inside the freshly placed fragment still need
        // hydration. Scoping to the wrapper plus the
        // :not([data-usephp-defer-loaded]) selector keeps it from matching
        // itself.
        processDeferred(placeholder);
    }

    // `force` is set only by reloadDefer(): it means "supersede whatever is
    // running for this wrapper" (the user explicitly asked for fresh data).
    // Unforced callers (processDeferred) instead yield to an in-flight
    // fetch, so a partial-submit re-scan can't pile a second request on top
    // of a reload already running for the same wrapper.
    async function fetchDeferred(placeholder, { force = false } = {}) {
        const url = placeholder.dataset.usephpDeferUrl;
        if (!url) return;

        // Defensive: a resolved reloadable wrapper is only re-fetched via
        // reloadDefer(), which clears this marker first. Any other caller
        // (a stray direct invocation) is a no-op.
        if (placeholder.hasAttribute('data-usephp-defer-loaded')) return;

        // Single-flight. An unforced caller bows out if a fetch is already
        // running; a forced one falls through and supersedes it (older
        // generation's response is discarded and its request aborted).
        if (deferInFlight.has(placeholder) && !force) return;

        const myGen = (deferGen.get(placeholder) || 0) + 1;
        deferGen.set(placeholder, myGen);
        deferInFlight.add(placeholder);
        const superseded = deferAbort.get(placeholder);
        if (superseded) superseded.abort();
        deferAbort.delete(placeholder);
        // Only the newest generation may place its result; an older,
        // superseded call returns silently. settle() releases the shared
        // in-flight/abort bookkeeping, but only if no newer generation has
        // taken ownership of it.
        const isCurrent = () => deferGen.get(placeholder) === myGen;
        const settle = () => {
            if (isCurrent()) {
                deferInFlight.delete(placeholder);
                deferAbort.delete(placeholder);
            }
        };

        // Component-declared opt-in. false → this component did not opt
        // into localStorage persistence, so L2 is bypassed for both reads
        // and writes and behaviour matches the old L1-only cache.
        const wantsLocalCache = placeholderWantsLocalCache(placeholder);
        // Optional hard-discard age for the persisted entry (0 = none).
        // Only meaningful alongside wantsLocalCache; it gates the L2 read
        // below and never affects L1, which is per-page anyway.
        const localCacheTtlMs = placeholderCacheTtlMs(placeholder);

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
            if (isCurrent()) placeDeferredFragment(placeholder, cached.cloneNode(true));
            settle();
            return;
        }

        // L2 hit: only when this component opted in. A previous page/tab
        // persisted this fragment. Promote it into L1 (pristine clone) so
        // subsequent same-page hits and LRU bookkeeping go through the
        // shared in-memory path.
        if (wantsLocalCache) {
            const persisted = readPersisted(url, localCacheTtlMs);
            if (persisted) {
                rememberDeferFragment(url, persisted.cloneNode(true));
                if (isCurrent()) placeDeferredFragment(placeholder, persisted);
                settle();
                return;
            }
        }

        placeholder.setAttribute('aria-busy', 'true');

        const ctrl = new AbortController();
        deferAbort.set(placeholder, ctrl);

        try {
            const response = await fetch(url, {
                method: 'GET',
                headers: { 'X-UsePHP-Defer': '1' },
                credentials: 'same-origin',
                signal: ctrl.signal,
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

            // Warm L1/L2 even if this generation ends up superseded — the
            // fragment is still valid for the URL and a later reader
            // benefits. Store a pristine clone before the original is
            // consumed by placement, which would otherwise leave the cached
            // entry empty.
            rememberDeferFragment(url, template.content.cloneNode(true));

            // Persist to L2 only when the component opted in via
            // `Defer::$localCache`. The HTTP response (Cache-Control or
            // otherwise) is intentionally not consulted for this decision.
            if (wantsLocalCache) {
                persistFragment(url, html);
            }

            // A newer reload may have superseded this response while it was
            // in flight; if so, discard it (the newer one will place) so
            // there is no double placement or stale-then-fresh flicker.
            if (!isCurrent()) return;
            // Placement (and nested-defer hydration) is centralised so the
            // reloadable wrapper-retention decision stays consistent with
            // the cache-hit paths above.
            placeDeferredFragment(placeholder, template.content);
        } catch (error) {
            // A supersede aborts the request on purpose — not a failure.
            if (error && error.name === 'AbortError') return;
            // Network/other error — leave the fallback in place but log so
            // the failure mode is visible to developers.
            console.warn('[usePHP] defer fetch failed:', error, url);
        } finally {
            settle();
        }
    }

    function processDeferred(root) {
        const scope = root || document;
        // A reloadable wrapper keeps `data-usephp-defer-url` after it
        // resolves so reloadDefer() can re-fetch it, but it must not be
        // re-fetched by a generic scan (initial load, or a partial form
        // submit that re-renders surrounding chrome). The resolved marker
        // excludes it; reloadDefer() clears that marker to re-arm.
        // Wrap rather than pass fetchDeferred directly: forEach would
        // otherwise hand it (element, index, array), and the numeric index
        // would land in the options arg. A generic scan is never forced.
        scope
            .querySelectorAll('[data-usephp-defer-url]:not([data-usephp-defer-loaded])')
            .forEach((el) => fetchDeferred(el));
    }

    // =====================================================================
    // Partial responses
    // =====================================================================

    // Swap a component's contents for the partial HTML the server returned
    // and pick up the refreshed snapshot it carries. Shared by the form
    // submit path and the snapshot restore path below.
    function applyPartial(component, html) {
        // Server-rendered HTML is trusted content from our endpoint
        component.innerHTML = html;

        // Update snapshot on component from hidden field in response
        const snapshotField = component.querySelector('[data-usephp-snapshot-update]');
        if (snapshotField) {
            component.dataset.usephpSnapshot = snapshotField.value;
            // Remove the hidden field as it's not needed in DOM
            snapshotField.remove();
        }

        saveSnapshot(component);
    }

    // =====================================================================
    // Client-side snapshot persistence
    // =====================================================================
    //
    // Snapshot storage keeps a component's state in the page itself, so a
    // reload normally starts over. A wrapper that carries
    // `data-usephp-persist="sessionStorage"` (or "localStorage") — emitted
    // when the component opts in via `fc(..., persist: ...)` or
    // `#[Component(persist: ...)]` — gets its latest snapshot mirrored into
    // that Web Storage after every partial update. On the next page load
    // the saved snapshot is POSTed back as a `restore` action (no state
    // change; the snapshot *is* the state) and the component is swapped
    // for the server's re-render. The server stays stateless and still
    // HMAC-verifies the snapshot, exactly as it does for any action.
    //
    // Entries are keyed by pathname + instance id, so the same component
    // on two pages keeps two independent states. A saved snapshot the
    // server rejects (e.g. the signing secret rotated) or answers with a
    // redirect is dropped so it is not retried on every load.

    const SNAPSHOT_PREFIX = 'usephp:snapshot:';

    function snapshotStore(component) {
        const kind = component.dataset.usephpPersist;
        if (kind !== 'sessionStorage' && kind !== 'localStorage') return null;
        try {
            const store = window[kind];
            const probe = SNAPSHOT_PREFIX + '__probe__';
            store.setItem(probe, '1');
            store.removeItem(probe);
            return store;
        } catch {
            return null;
        }
    }

    function snapshotKey(component) {
        return SNAPSHOT_PREFIX + location.pathname + '#' + component.dataset.usephp;
    }

    function saveSnapshot(component) {
        const store = snapshotStore(component);
        if (!store) return;
        try {
            const snapshot = component.dataset.usephpSnapshot;
            if (snapshot) {
                store.setItem(snapshotKey(component), snapshot);
            } else {
                store.removeItem(snapshotKey(component));
            }
        } catch {
            // Quota exceeded or storage revoked mid-session: the page keeps
            // working from the DOM snapshot, it just won't survive a reload.
        }
    }

    function forgetSnapshot(component) {
        const store = snapshotStore(component);
        if (!store) return;
        try {
            store.removeItem(snapshotKey(component));
        } catch {}
    }

    async function restoreSnapshot(component) {
        const store = snapshotStore(component);
        if (!store) return;
        const key = snapshotKey(component);
        let saved;
        try {
            saved = store.getItem(key);
        } catch {
            return;
        }
        if (!saved || saved === component.dataset.usephpSnapshot) return;

        const instanceId = component.dataset.usephp;
        const formData = new FormData();
        formData.set('_usephp_component', instanceId);
        formData.set('_usephp_action', JSON.stringify({
            type: 'restore',
            payload: {},
            componentId: instanceId,
            storageType: 'snapshot',
        }));
        formData.set('_usephp_snapshot', saved);
        // The session-bound CSRF token (when a session is active) is embedded
        // in every usephp form; borrow it from one of ours.
        const csrf = component.querySelector('input[name="_usephp_csrf"]');
        if (csrf) formData.set('_usephp_csrf', csrf.value);

        component.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(location.href, {
                method: 'POST',
                headers: { 'X-UsePHP-Partial': '1' },
                body: formData,
            });
            if (response.redirected || !response.ok) {
                forgetSnapshot(component);
                return;
            }
            applyPartial(component, await response.text());
            processDeferred(component);
        } catch {
            // Network failure: keep the saved snapshot for the next load.
        } finally {
            component.removeAttribute('aria-busy');
        }
    }

    function restoreSnapshots(root) {
        (root || document)
            .querySelectorAll('[data-usephp][data-usephp-persist]')
            .forEach((el) => restoreSnapshot(el));
    }

    function forgetSnapshots(root) {
        (root || document)
            .querySelectorAll('[data-usephp][data-usephp-persist]')
            .forEach((el) => forgetSnapshot(el));
    }

    usePHP.restoreSnapshots = restoreSnapshots;
    usePHP.forgetSnapshots = forgetSnapshots;

    // Reconcile the persisted-cache version before the first read so a
    // stale release is dropped rather than served.
    reconcileVersion();

    function boot() {
        processDeferred();
        restoreSnapshots();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
