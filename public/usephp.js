/**
 * usePHP - Minimal JS for partial page updates
 * Falls back to full page reload if JS is disabled
 * Supports snapshot-based state management
 *
 * Security note: innerHTML is used intentionally here as the HTML content
 * comes from our own server endpoint, not from user input.
 */
(function() {
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
            } else {
                form.submit();
            }
        } catch {
            form.submit();
        } finally {
            component.removeAttribute('aria-busy');
        }
    });
})();
