(() => {
    'use strict';

    const filters = document.querySelector('[data-pricing-filters]');
    if (filters) {
        const search = filters.querySelector('[data-pricing-search]');
        const family = filters.querySelector('[data-pricing-family]');
        const brand = filters.querySelector('[data-pricing-brand]');
        const status = filters.querySelector('[data-pricing-status]');
        const apply = () => {
            document.querySelectorAll('[data-pricing-row]').forEach((row) => {
                const visible = (!search.value || row.dataset.pricingSearchText.includes(search.value.trim().toLowerCase()))
                    && (!family.value || row.dataset.pricingFamilyValue === family.value)
                    && (!brand.value || row.dataset.pricingBrandValue === brand.value)
                    && (!status.value || row.dataset.pricingStatusValue === status.value);
                row.hidden = !visible;
                const edit = row.querySelector('[data-pricing-edit]');
                if (!visible && edit) {
                    const detail = document.querySelector(`[data-pricing-edit-row="${edit.dataset.pricingEdit}"]`);
                    if (detail) detail.hidden = true;
                    edit.setAttribute('aria-expanded', 'false');
                }
            });
        };
        [search, family, brand, status].forEach((control) => control.addEventListener('input', apply));
        apply();
    }

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
