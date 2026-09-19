(() => {
    'use strict';

    const initialise = () => {
        const root =
            document.querySelector('[data-quick-sale-form]');

        if (!root) {
            return;
        }

        const lines =
            root.querySelector('[data-quick-lines]');

        const template =
            root.querySelector('[data-quick-template]');

        const add =
            root.querySelector('[data-quick-add]');

        if (!lines || !template) {
            return;
        }

        const rows = () =>
            Array.from(
                lines.querySelectorAll('[data-quick-line]')
            );

        const productSelect = (row) => row.querySelector('[name$="[product_id]"], [data-field="product_id"]');
        const field = (row, key) => row.querySelector(`[data-variant-${key}]`);
        const options = (row) => Array.from(productSelect(row)?.options || []).filter((option) => option.value);
        const unique = (values) => [...new Map(values.map(([id, label]) => [id, label])).entries()];
        const setOptions = (select, values, placeholder) => {
            const selected = select.value;
            select.replaceChildren(new Option(placeholder, ''));
            values.forEach(([value, label]) => select.add(new Option(label, value)));
            select.value = values.some(([value]) => value === selected) ? selected : '';
        };
        const selectedSku = (row) => productSelect(row)?.selectedOptions?.[0];
        const refreshPreview = (row) => {
            const option = selectedSku(row);
            const priced = option?.value && option.dataset.priced === '1';
            const quantity = Number(row.querySelector('input[type="number"]')?.value || 0);
            const unit = Number(option?.dataset.price || 0);
            const discountUnit = Number(option?.dataset.discount || 0);
            const discountPercent = Number(option?.dataset.discountPercent || 0);
            const taxPercent = Number(option?.dataset.taxPercent || 0);
            const gross = Math.round(unit * quantity * 100) / 100;
            const discountTotal = Math.round(discountUnit * quantity * 100) / 100;
            const taxTotal = Math.round((gross - discountTotal) * taxPercent) / 100;
            const put = (selector, value) => { const node = row.querySelector(selector); if (node) node.textContent = value; };
            put('[data-quick-unit]', priced ? unit.toFixed(2) : option?.value ? 'Not priced' : '—');
            put('[data-quick-discount-unit]', priced ? discountUnit.toFixed(2) : '—');
            put('[data-quick-discount-percent]', priced ? discountPercent.toFixed(2) : '—');
            put('[data-quick-tax-percent]', priced ? taxPercent.toFixed(2) : '—');
            put('[data-quick-available]', option?.value ? (option.dataset.available || '0') : '—');
            put('[data-quick-gross]', priced ? gross.toFixed(2) : '—');
            put('[data-quick-discount-total]', priced ? discountTotal.toFixed(2) : '—');
            put('[data-quick-tax-total]', priced ? taxTotal.toFixed(2) : '—');
            put('[data-quick-net]', priced ? (gross - discountTotal + taxTotal).toFixed(2) : '—');
            const warning = row.querySelector('[data-quick-warning]');
            if (warning) warning.hidden = !option?.value || priced;
        };
        const refreshVariants = (row, changed = '') => {
            const family = field(row, 'family'); const subtype = field(row, 'subtype');
            const brand = field(row, 'brand'); const model = field(row, 'model'); const sku = productSelect(row);
            if (!family || !subtype || !brand || !model || !sku) return;
            if (changed === 'family') { subtype.value = ''; brand.value = ''; model.value = ''; sku.value = ''; }
            if (changed === 'subtype') { brand.value = ''; model.value = ''; sku.value = ''; }
            if (changed === 'brand') { model.value = ''; sku.value = ''; }
            if (changed === 'model') sku.value = '';
            subtype.disabled = family.value !== 'mifi';
            const eligible = options(row).filter((option) => option.dataset.activeModel === '1'
                && option.dataset.family === family.value
                && (family.value !== 'mifi' || option.dataset.subtype === subtype.value));
            setOptions(brand, unique(eligible.map((option) => [option.dataset.brandId, option.dataset.brandName])), 'Select brand');
            setOptions(model, unique(eligible.filter((option) => option.dataset.brandId === brand.value).map((option) => [option.dataset.modelId, option.dataset.modelName])), 'Select model');
            options(row).forEach((option) => {
                const visible = family.value === '' ? !option.dataset.modelId
                    : option.dataset.activeModel === '1' && option.dataset.family === family.value
                        && (family.value !== 'mifi' || option.dataset.subtype === subtype.value)
                        && option.dataset.brandId === brand.value && option.dataset.modelId === model.value;
                option.disabled = !visible;
                option.hidden = !visible;
            });
            if (sku.selectedOptions[0]?.disabled) sku.value = '';
            refreshPreview(row);
        };
        const initialiseVariants = (row) => {
            const selected = selectedSku(row);
            if (selected?.value && selected.dataset.modelId) {
                field(row, 'family').value = selected.dataset.family;
                field(row, 'subtype').value = selected.dataset.subtype;
                refreshVariants(row);
                field(row, 'brand').value = selected.dataset.brandId;
                refreshVariants(row);
                field(row, 'model').value = selected.dataset.modelId;
                refreshVariants(row);
                productSelect(row).value = selected.value;
            }
            refreshVariants(row);
        };

        const reindex = () => {
            rows().forEach((row, index) => {
                row.querySelectorAll('[name], [data-field]')
                    .forEach((field) => {
                        const dataKey =
                            field.getAttribute('data-field');

                        const name =
                            field.getAttribute('name') || '';

                        const match =
                            name.match(/\]\[([^\]]+)\]$/);

                        const key =
                            dataKey || (match ? match[1] : '');

                        if (!key) {
                            return;
                        }

                        field.setAttribute(
                            'name',
                            `lines[${index}][${key}]`
                        );
                    });
            });
        };

        const resetRow = (row) => {
            const product = productSelect(row);
            const quantity = row.querySelector(
                'input[type="number"]'
            );

            if (product) {
                product.value = '';
            }
            ['family','subtype','brand','model'].forEach((key) => { const select = field(row,key); if (select) select.value = ''; });

            if (quantity) {
                quantity.value = '0';
            }
            refreshVariants(row, 'family');
        };

        if (add) {
            add.addEventListener('click', () => {
                if (rows().length >= 20) {
                    return;
                }

                const fragment =
                    template.content.cloneNode(true);

                lines.appendChild(fragment);
                reindex();
                const newRows = rows();
                initialiseVariants(newRows[newRows.length - 1]);
                const product = productSelect(newRows[newRows.length - 1]);

                product?.focus();
            });
        }

        lines.addEventListener('click', (event) => {
            const target = event.target;

            if (!(target instanceof Element)) {
                return;
            }

            const button =
                target.closest('[data-quick-remove]');

            if (!button) {
                return;
            }

            const currentRows = rows();

            if (currentRows.length <= 1) {
                resetRow(currentRows[0]);
                return;
            }

            const row =
                button.closest('[data-quick-line]');

            row?.remove();
            reindex();
        });

        lines.addEventListener('change', (event) => {
            const row = event.target.closest?.('[data-quick-line]');
            if (!row) return;
            for (const key of ['family','subtype','brand','model']) {
                if (event.target === field(row,key)) { refreshVariants(row,key); return; }
            }
            if (event.target === productSelect(row)) refreshPreview(row);
        });
        lines.addEventListener('input', (event) => {
            const row = event.target.closest?.('[data-quick-line]');
            if (row && event.target.matches('input[type="number"]')) refreshPreview(row);
        });

        root.addEventListener('submit', (event) => {
            const selected = rows().map((row) => ({
                product: selectedSku(row),
                quantity: Number(row.querySelector('input[type="number"]')?.value || 0),
            })).filter((line) => line.quantity >= 1);
            let message = root.querySelector('[data-quick-sale-error]');
            let error = '';
            if (selected.length === 0) error = 'At least 1 item is needed. Select a product and quantity, then send to your manager.';
            else if (selected.some((line) => !line.product?.value)) error = 'Select a product for every quantity entered.';
            else if (selected.some((line) => line.product.dataset.priced !== '1')) error = 'The selected SKU has no current price. Ask an administrator to update Pricelists.';
            if (error) {
                event.preventDefault();
                if (!message) {
                    message = document.createElement('div');
                    message.className = 'alert alert-danger';
                    message.setAttribute('role', 'alert');
                    message.dataset.quickSaleError = '';
                    root.prepend(message);
                }
                message.textContent = error;
                message.scrollIntoView({block: 'nearest'});
            } else if (message) message.remove();
        });

        reindex();
        rows().forEach(initialiseVariants);
    };

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initialise,
            { once: true }
        );
    } else {
        initialise();
    }
})();
/* QUICK_SALE_REPORT_UI_V1 */
(() => {
                const form =
                    document.querySelector('[data-quick-sale-report]');

                if (!form) return;

                const clamp = (value, min, max) =>
                    Math.min(max, Math.max(min, value));

                form.querySelectorAll('[data-report-line]')
                    .forEach((row) => {
                        const allocated =
                            Number(row.dataset.allocated || 0);

                        const sold =
                            row.querySelector('[data-sold]');

                        const returned =
                            row.querySelector('[data-returned]');

                        const format = (value) =>
                            clamp(value, 0, allocated).toFixed(3);

                        const validateQuantities = () => {
                            const soldValue =
                                Number(sold.value || 0);

                            const returnedValue =
                                Number(returned.value || 0);

                            const valid =
                                soldValue >= 0
                                && returnedValue >= 0
                                && soldValue <= allocated
                                && returnedValue <= allocated
                                && Math.abs(
                                    allocated
                                    - (soldValue + returnedValue)
                                ) <= 0.0005;

                            const message = valid
                                ? ''
                                : 'Sold + Returned must equal Allocated.';

                            sold.setCustomValidity(message);
                            returned.setCustomValidity(message);
                        };

                        sold.addEventListener(
                            'input',
                            validateQuantities
                        );

                        returned.addEventListener(
                            'input',
                            validateQuantities
                        );

                        validateQuantities();
                    });
                /*
                 * Evidence attachment rows.
                 * Each row owns one native file input.
                 * This avoids FileList replacement/DataTransfer problems.
                 */
                const evidenceRows =
                    form.querySelector('[data-evidence-rows]');

                const addEvidenceButton =
                    form.querySelector('[data-add-evidence]');

                const evidenceCount =
                    form.querySelector('[data-evidence-count]');

                if (evidenceRows && addEvidenceButton) {
                    const maxEvidenceFiles = 10;

                    const updateEvidenceUi = () => {
                        const rows = Array.from(
                            evidenceRows.querySelectorAll(
                                '[data-evidence-row]'
                            )
                        );

                        const selected = rows.filter((row) => {
                            const input =
                                row.querySelector(
                                    '[data-evidence-input]'
                                );

                            return input
                                && input.files
                                && input.files.length > 0;
                        }).length;

                        if (evidenceCount) {
                            evidenceCount.textContent =
                                selected
                                + ' of '
                                + maxEvidenceFiles
                                + ' files selected';
                        }

                        addEvidenceButton.disabled =
                            rows.length >= maxEvidenceFiles;
                    };

                    const createEvidenceRow = () => {
                        const currentRows =
                            evidenceRows.querySelectorAll(
                                '[data-evidence-row]'
                            );

                        if (
                            currentRows.length
                            >= maxEvidenceFiles
                        ) {
                            return;
                        }

                        const row =
                            document.createElement('div');

                        row.setAttribute(
                            'data-evidence-row',
                            ''
                        );

                        row.style.display = 'flex';
                        row.style.gap = '8px';
                        row.style.alignItems = 'center';

                        const input =
                            document.createElement('input');

                        input.type = 'file';
                        input.name = 'evidence_files[]';

                        input.setAttribute(
                            'data-evidence-input',
                            ''
                        );

                        input.accept =
                            '.pdf,.png,.jpg,.jpeg,'
                            + 'application/pdf,'
                            + 'image/png,image/jpeg';

                        input.style.flex = '1';

                        const remove =
                            document.createElement('button');

                        remove.type = 'button';
                        remove.className =
                            'btn btn-secondary';

                        remove.setAttribute(
                            'data-remove-evidence',
                            ''
                        );

                        remove.textContent = 'Remove';

                        input.addEventListener(
                            'change',
                            updateEvidenceUi
                        );

                        remove.addEventListener(
                            'click',
                            () => {
                                row.remove();

                                if (
                                    !evidenceRows.querySelector(
                                        '[data-evidence-row]'
                                    )
                                ) {
                                    createEvidenceRow();
                                }

                                updateEvidenceUi();
                            }
                        );

                        row.appendChild(input);
                        row.appendChild(remove);

                        evidenceRows.appendChild(row);

                        updateEvidenceUi();
                    };

                    evidenceRows
                        .querySelectorAll(
                            '[data-evidence-row]'
                        )
                        .forEach((row) => {
                            const input =
                                row.querySelector(
                                    '[data-evidence-input]'
                                );

                            const remove =
                                row.querySelector(
                                    '[data-remove-evidence]'
                                );

                            if (input) {
                                input.addEventListener(
                                    'change',
                                    updateEvidenceUi
                                );
                            }

                            if (remove) {
                                remove.addEventListener(
                                    'click',
                                    () => {
                                        row.remove();

                                        if (
                                            !evidenceRows.querySelector(
                                                '[data-evidence-row]'
                                            )
                                        ) {
                                            createEvidenceRow();
                                        }

                                        updateEvidenceUi();
                                    }
                                );
                            }
                        });

                    addEvidenceButton.addEventListener(
                        'click',
                        createEvidenceRow
                    );

                    updateEvidenceUi();
                }
})();
