'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const button = document.querySelector(
        '[data-sidebar-toggle]'
    );

    const sidebar = document.querySelector(
        '[data-sidebar]'
    );

    if (!button || !sidebar) {
        return;
    }

    button.addEventListener('click', () => {
        const isOpen = sidebar.classList.toggle(
            'is-open'
        );

        button.setAttribute(
            'aria-expanded',
            String(isOpen)
        );
    });
});