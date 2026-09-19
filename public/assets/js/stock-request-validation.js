(() => {
    'use strict';

    document.querySelectorAll('[data-stock-request-quantities]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const quantities = [...form.querySelectorAll('input[name="quantity[]"]')];
            const valid = quantities.some((input) => {
                const quantity = Number(input.value);
                const product = input.closest('.stock-request-line, .form-grid')?.querySelector('select[name="product_id[]"]');
                return Number.isFinite(quantity) && quantity >= 1 && !!product?.value;
            });
            let message = form.querySelector('[data-stock-request-error]');
            if (!valid) {
                event.preventDefault();
                if (!message) {
                    message = document.createElement('p');
                    message.className = 'alert alert-danger';
                    message.setAttribute('role', 'alert');
                    message.dataset.stockRequestError = '';
                    form.prepend(message);
                }
                message.textContent = 'Add at least one product and submit.';
                message.scrollIntoView({block: 'nearest'});
            } else if (message) {
                message.remove();
            }
        });
    });
})();
