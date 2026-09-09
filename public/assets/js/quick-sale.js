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
            const product = row.querySelector('select');
            const quantity = row.querySelector(
                'input[type="number"]'
            );

            if (product) {
                product.value = '';
            }

            if (quantity) {
                quantity.value = '1';
            }
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
                const product =
                    newRows[newRows.length - 1]
                        ?.querySelector('select');

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

        reindex();
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
