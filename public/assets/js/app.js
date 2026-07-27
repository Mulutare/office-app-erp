'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const button = document.querySelector(
        '[data-sidebar-toggle]'
    );

    const sidebar = document.querySelector(
        '[data-sidebar]'
    );

    if (button && sidebar) {
        button.addEventListener('click', () => {
            const isOpen = sidebar.classList.toggle(
                'is-open'
            );

            button.setAttribute(
                'aria-expanded',
                String(isOpen)
            );
        });
    }

    document
        .querySelectorAll('[data-password-toggle]')
        .forEach((toggle) => {
            toggle.addEventListener('click', () => {
                const inputId = toggle.getAttribute(
                    'data-password-toggle'
                );
                const input = inputId
                    ? document.getElementById(inputId)
                    : null;

                if (
                    !(input instanceof HTMLInputElement)
                ) {
                    return;
                }

                const show =
                    input.type === 'password';

                input.type = show
                    ? 'text'
                    : 'password';
                toggle.textContent = show
                    ? 'Hide'
                    : 'Show';
                toggle.setAttribute(
                    'aria-pressed',
                    String(show)
                );
                toggle.setAttribute(
                    'aria-label',
                    show
                        ? 'Hide password'
                        : 'Show password'
                );
            });
        });
});
