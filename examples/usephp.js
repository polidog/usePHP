/**
 * usePHP - Minimal client-side JavaScript for server-driven components
 *
 * Security note: This library updates the DOM with HTML from the same-origin
 * PHP server. The server-rendered HTML is trusted content from the application.
 */
(function() {
    'use strict';

    const UsePHP = {
        /**
         * Initialize usePHP event handling
         */
        init() {
            document.addEventListener('click', this.handleClick.bind(this));
            document.addEventListener('change', this.handleChange.bind(this));
            document.addEventListener('input', this.handleInput.bind(this));
            document.addEventListener('submit', this.handleSubmit.bind(this));
        },

        /**
         * Handle click events
         */
        handleClick(event) {
            this.handleEvent(event, 'click');
        },

        /**
         * Handle change events
         */
        handleChange(event) {
            this.handleEvent(event, 'change');
        },

        /**
         * Handle input events
         */
        handleInput(event) {
            this.handleEvent(event, 'input');
        },

        /**
         * Handle submit events
         */
        handleSubmit(event) {
            this.handleEvent(event, 'submit');
        },

        /**
         * Generic event handler
         */
        handleEvent(event, eventType) {
            const target = event.target.closest('[data-usephp-event="' + eventType + '"]');

            if (!target) {
                return;
            }

            const actionJson = target.getAttribute('data-usephp-action');

            if (!actionJson) {
                return;
            }

            event.preventDefault();

            const component = target.closest('[data-usephp-component]');

            if (!component) {
                console.error('usePHP: Component container not found');
                return;
            }

            const componentId = component.getAttribute('data-usephp-component');

            try {
                const action = JSON.parse(actionJson);
                this.executeAction(componentId, action, component);
            } catch (e) {
                console.error('usePHP: Failed to parse action', e);
            }
        },

        /**
         * Execute an action via AJAX
         */
        async executeAction(componentId, action, container) {
            try {
                // Add loading state
                container.setAttribute('data-usephp-loading', 'true');

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-UsePHP-Action': '1',
                    },
                    body: JSON.stringify({
                        componentId: componentId,
                        action: action,
                    }),
                });

                if (!response.ok) {
                    throw new Error('HTTP error: ' + response.status);
                }

                const result = await response.json();

                if (result.error) {
                    console.error('usePHP: Server error:', result.error);
                    return;
                }

                if (result.html) {
                    this.updateDOM(container, result.html);
                }
            } catch (e) {
                console.error('usePHP: Action failed', e);
            } finally {
                container.removeAttribute('data-usephp-loading');
            }
        },

        /**
         * Update the DOM with server-rendered HTML
         *
         * The HTML content comes from the same-origin PHP server and is
         * considered trusted. All user input is escaped by the Renderer.
         */
        updateDOM(container, trustedHtml) {
            // Use morphdom if available for smooth DOM diffing
            if (typeof morphdom !== 'undefined') {
                const temp = document.createElement('div');
                temp.innerHTML = trustedHtml;

                morphdom(container, temp, {
                    childrenOnly: true,
                    onBeforeElUpdated(fromEl, toEl) {
                        // Preserve focus and value on active input elements
                        if (fromEl === document.activeElement &&
                            fromEl.tagName === 'INPUT') {
                            toEl.value = fromEl.value;
                            return false;
                        }
                        return true;
                    }
                });
            } else {
                // Fallback: replace children using DOM Range API
                const range = document.createRange();
                range.selectNodeContents(container);
                range.deleteContents();

                const fragment = range.createContextualFragment(trustedHtml);
                container.appendChild(fragment);
            }
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => UsePHP.init());
    } else {
        UsePHP.init();
    }

    // Export for use in modules
    if (typeof window !== 'undefined') {
        window.UsePHP = UsePHP;
    }
})();
