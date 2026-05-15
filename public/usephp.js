/**
 * usePHP - Minimal JS for partial page updates and deferred component fetches.
 * Falls back to full page reload if JS is disabled.
 * Supports snapshot-based state management.
 *
 * Security note: innerHTML/outerHTML are used intentionally here as the HTML
 * content comes from our own server endpoint, not from user input.
 */
(function() {
    // Cache of already-fetched defer fragments keyed by HMAC signature.
    // `sig = HMAC(fqcn + props)`, so identical sigs imply identical server
    // output — partial updates that re-emit the same defer placeholder
    // (e.g. layout chrome around a state-driven component) can reuse the
    // fragment instead of re-fetching. In-memory only; a full page reload
    // clears it.
    const deferCache = new Map();

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
        const payload = placeholder.dataset.usephpDeferPayload;
        const sig = placeholder.dataset.usephpDeferSig;
        if (!payload || !sig) return;

        // Cache hit: skip the network round-trip and reuse the previously
        // fetched fragment. Clone so each placeholder gets its own nodes,
        // and re-run processDeferred so nested placeholders (which the
        // cached fragment still carries) hydrate too — they will normally
        // also hit the cache by their own sig.
        const cached = deferCache.get(sig);
        if (cached) {
            const clone = cached.cloneNode(true);
            processDeferred(clone);
            placeholder.replaceWith(clone);
            return;
        }

        placeholder.setAttribute('aria-busy', 'true');

        try {
            const formData = new FormData();
            formData.set('_usephp_defer_payload', payload);
            formData.set('_usephp_defer_sig', sig);

            const response = await fetch(location.href, {
                method: 'POST',
                headers: { 'X-UsePHP-Defer': '1' },
                body: formData,
            });

            if (!response.ok) {
                // Log so misconfiguration (route only handles GET, server
                // returns 400 because of bad signature, etc.) is discoverable
                // — otherwise the user just sees the skeleton stay forever.
                console.warn(
                    '[usePHP] defer fetch returned non-OK status:',
                    response.status,
                    response.statusText,
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
            deferCache.set(sig, template.content.cloneNode(true));

            // A deferred component's rendered output may itself contain
            // nested defer placeholders. Kick off their fetches before
            // moving the fragment into the DOM so they hydrate too.
            processDeferred(template.content);
            placeholder.replaceWith(template.content);
        } catch (error) {
            // Network/other error — leave the fallback in place but log so
            // the failure mode is visible to developers.
            console.warn('[usePHP] defer fetch failed:', error);
        }
    }

    function processDeferred(root) {
        const scope = root || document;
        scope.querySelectorAll('[data-usephp-defer-payload]').forEach(fetchDeferred);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => processDeferred());
    } else {
        processDeferred();
    }
})();
