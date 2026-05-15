/**
 * usePHP - Minimal JS for partial page updates and deferred component fetches.
 * Falls back to full page reload if JS is disabled.
 * Supports snapshot-based state management.
 *
 * Security note: innerHTML/outerHTML are used intentionally here as the HTML
 * content comes from our own server endpoint, not from user input.
 */
(function() {
    // Cache of already-fetched defer fragments keyed by the placeholder URL.
    // Same URL → same server output, so partial updates that re-emit the
    // same defer placeholder (e.g. layout chrome around a state-driven
    // component) can reuse the fragment instead of re-fetching. In-memory
    // only; a full page reload clears it. The browser's HTTP cache also
    // applies per the endpoint's Cache-Control, so this is a per-page
    // de-duplication layer on top of the normal cache.
    //
    // Capped to bound memory: list-style pages that defer per-row content
    // with distinct query params (e.g. `<Comment defer="comment" id={$id} />`
    // for many rows) would otherwise grow the cache without limit. When the
    // cap is hit we drop the least-recently inserted entry — Maps preserve
    // insertion order in JS, so reinserting on hit lifts the entry to the
    // newest slot and gives us cheap LRU semantics.
    const DEFER_CACHE_MAX = 64;
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

    async function fetchDeferred(placeholder) {
        const url = placeholder.dataset.usephpDeferUrl;
        if (!url) return;

        // Cache hit: skip the network round-trip and reuse the previously
        // fetched fragment. Clone so each placeholder gets its own nodes,
        // and re-run processDeferred so nested placeholders (which the
        // cached fragment still carries) hydrate too — they will normally
        // also hit the cache by their own URL.
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

            // Store a pristine clone before the original fragment is
            // consumed by replaceWith — moving children into the DOM would
            // leave the cached entry empty otherwise.
            rememberDeferFragment(url, template.content.cloneNode(true));

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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => processDeferred());
    } else {
        processDeferred();
    }
})();
