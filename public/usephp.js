/**
 * usePHP - Minimal JS for partial page updates (~40 lines)
 * Falls back to full page reload if JS is disabled
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
            const response = await fetch(location.href, {
                method: 'POST',
                headers: { 'X-UsePHP-Partial': '1' },
                body: new FormData(form)
            });

            if (response.ok) {
                const html = await response.text();
                component.innerHTML = html;
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
