(() => {
    'use strict';

    document.querySelectorAll('[data-pricing-edit]').forEach((button) => {
        button.addEventListener('click', () => {
            const detail = document.querySelector(`[data-pricing-edit-row="${button.dataset.pricingEdit}"]`);
            if (!detail) return;
            detail.hidden = !detail.hidden;
            button.setAttribute('aria-expanded', String(!detail.hidden));
            if (!detail.hidden) detail.querySelector('input[name="proposed_price"]')?.focus();
        });
    });
    document.querySelectorAll('[data-pricing-cancel]').forEach((button) => {
        button.addEventListener('click', () => {
            const detail = document.querySelector(`[data-pricing-edit-row="${button.dataset.pricingCancel}"]`);
            if (detail) detail.hidden = true;
            document.querySelector(`[data-pricing-edit="${button.dataset.pricingCancel}"]`)?.setAttribute('aria-expanded', 'false');
        });
    });
})();
