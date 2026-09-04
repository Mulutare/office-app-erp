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