<?php

declare(strict_types=1);

$quick = is_array($data['quickSale'] ?? null)
    ? $data['quickSale']
    : [];

$eligible = !empty($quick['eligible']);
$products = is_array($quick['products'] ?? null)
    ? $quick['products']
    : [];

$old = is_array($data['old'] ?? null)
    ? $data['old']
    : [];

$errors = is_array($data['errors'] ?? null)
    ? $data['errors']
    : [];

$notice = is_array($data['notice'] ?? null)
    ? $data['notice']
    : null;

$lines = is_array($old['lines'] ?? null)
    ? $old['lines']
    : [];

if ($lines === []) {
    $lines = [[]];
}

$currency = (string) ($quick['currency'] ?? 'ETB');

$tasks = is_array($quick['tasks'] ?? null)
    ? $quick['tasks']
    : [];

$history = is_array($quick['history'] ?? null)
    ? $quick['history']
    : [];

$productOptions = static function (
    array $products,
    int $selected
): void {
    echo '<option value="">Select product</option>';

    foreach ($products as $product) {
        $id = (int) ($product['product_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $label = trim(
            (string) ($product['sku'] ?? '')
            . ' - '
            . (string) ($product['name'] ?? '')
        );

        echo '<option value="' . e($id) . '"'
            . ' data-family="' . e((string)($product['product_family']??'')) . '"'
            . ' data-subtype="' . e((string)($product['mifi_subtype']??'')) . '"'
            . ' data-brand-id="' . e((string)($product['brand_id']??'')) . '"'
            . ' data-brand-name="' . e((string)($product['brand_name']??'')) . '"'
            . ' data-model-id="' . e((string)($product['model_id']??'')) . '"'
            . ' data-model-name="' . e((string)($product['model_name']??'')) . '"'
            . ' data-active-model="' . (!empty($product['model_active'])&&!empty($product['brand_active'])?'1':'0') . '"'
            . ' data-price="' . e((string)($product['display_price']??$product['unit_price']??0)) . '"'
            . ' data-priced="' . (!empty($product['display_priced'])?'1':'0') . '"'
            . ' data-discount="' . e((string)($product['display_discount']??0)) . '"'
            . ' data-discount-percent="' . e((string)($product['display_discount_percent']??0)) . '"'
            . ' data-tax-percent="' . e((string)($product['display_tax_percent']??0)) . '"'
            . ' data-available="' . e((string)($product['available_quantity']??0)) . '"'
            . ($id === $selected ? ' selected' : '')
            . '>'
            . e($label)
            . '</option>';
    }
};
?>

<div class="sales-workspace quick-sale-shell">

    <div class="page-actions">
    </div>

    <?php if ($notice !== null): ?>
        <div class="alert alert-success">
            <?= e($notice['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger">
            <?= e(is_array($error)
                ? (string) ($error['message'] ?? 'Unable to continue.')
                : (string) $error) ?>
        </div>
    <?php endforeach; ?>

    <?php if (!$eligible): ?>

        <section class="card quick-sale-card">
            <h2>Quick Sale unavailable</h2>
            <p>
                <?= e((string) (
                    $quick['error']
                    ?? 'Your DSA/DSP Sales setup is incomplete.'
                )) ?>
            </p>
        </section>

    <?php else: ?>

        <header class="quick-sale-header">
            <div>
                <p class="eyebrow">DSA / DSP</p>
                <h2>Quick Sale</h2>
                <p>
                    Select the product and quantity.
                    Everything else is automatic.
                </p>
            </div>
        </header>

        <section class="quick-sale-context">
            <div class="quick-sale-context-item">
                <span>You</span>
                <strong>
                    <?= e((string) (
                        $quick['actor']['display_name']
                        ?? ''
                    )) ?>
                </strong>
            </div>

            <div class="quick-sale-context-item">
                <span>Shop / Team</span>
                <strong>
                    <?= e((string) (
                        $quick['team']['name']
                        ?? ''
                    )) ?>
                </strong>
            </div>

            <div class="quick-sale-context-item">
                <span>Manager</span>
                <strong>
                    <?= e((string) (
                        $quick['manager']['name']
                        ?? ''
                    )) ?>
                </strong>
            </div>

            <div class="quick-sale-context-item">
                <span>Stock source</span>
                <strong>
                    <?= e((string) (
                        $quick['warehouse']['name']
                        ?? ''
                    )) ?>
                </strong>
            </div>
        </section>

        <section class="card quick-sale-card">
            <div class="section-heading">
                <div>
                    <h3>My sale status</h3>
                    <p>
                        Orders you sent to your Shop Manager.
                    </p>
                </div>
            </div>

            <?php if ($tasks === []): ?>

                <p>No task.</p>

            <?php else: ?>

                <?php foreach ($tasks as $task): ?>
                    <?php
                    $taskStatus = (string) ($task['status'] ?? '');

                    $taskLabel = match ($taskStatus) {
                        'submitted' =>
                            'Waiting for manager',
                        'allocated' =>
                            'Approved - Ready to sell',
                        'sold' =>
                            'Completed',
                        'cancelled' =>
                            'Rejected / Cancelled',
                        'return_requested' =>
                            'Return requested',
                        'returned' =>
                            'Returned',
                        default =>
                            strtoupper($taskStatus),
                    };
                    ?>

                    <div class="quick-sale-read-line">
                        <div>
                            <strong>
                                <?= e(
                                    $task['quotation_number']
                                    ?? 'Quick Sale'
                                ) ?>
                            </strong>

                            <span>
                                <?= e($taskLabel) ?>
                            </span>
                        </div>

                        <div>
                            <strong>
                                <?= e(
                                    ($task['currency'] ?? $currency)
                                    . ' '
                                    . number_format(
                                        (float) (
                                            $task['total_amount']
                                            ?? 0
                                        ),
                                        2
                                    )
                                ) ?>
                            </strong>

                            <a
                                href="<?= e(appBasePath()) ?>/sales/quick-sale/<?= e(
                                    $task['quick_sale_id']
                                ) ?>"
                            >
                                View
                            </a>
                        </div>
                    </div>

                <?php endforeach; ?>

            <?php endif; ?>
        </section>

        <form
            method="post"
            action="<?= e(appBasePath()) ?>/sales/quick-sale"
            class="quick-sale-form"
            data-quick-sale-form
        >
            <?= csrfField() ?>

            <section class="card quick-sale-card">
                <div class="section-heading">
                    <div>
                        <h3>Products</h3>
                        <p>
                            Price list, date, customer, team,
                            discount and tax are handled automatically.
                        </p>
                    </div>

                    <button
                        class="btn btn-secondary"
                        type="button"
                        data-quick-add
                    >
                        + Add item
                    </button>
                </div>

                <div data-quick-lines>
                    <?php foreach ($lines as $index => $line): ?>
                        <div
                            class="quick-sale-item"
                            data-quick-line
                        >
                            <div class="form-field"><label>Type</label><select name="lines[<?=e($index)?>][product_family]" data-variant-family><option value="">Legacy / Other</option><option value="mobile">Mobile</option><option value="mifi">MiFi</option></select></div>
                            <div class="form-field"><label>MiFi subtype</label><select name="lines[<?=e($index)?>][mifi_subtype]" data-variant-subtype><option value="">Select type first</option><option value="portable">Portable</option><option value="non_portable">Non-Portable</option></select></div>
                            <div class="form-field"><label>Brand</label><select name="lines[<?=e($index)?>][brand_id]" data-variant-brand><option value="">Select type first</option></select></div>
                            <div class="form-field"><label>Model</label><select name="lines[<?=e($index)?>][model_id]" data-variant-model><option value="">Select brand first</option></select></div>
                            <div class="form-field">
                                <label>Product</label>
                                <select
                                    name="lines[<?= e($index) ?>][product_id]"
                                >
                                    <?php
                                    $productOptions(
                                        $products,
                                        (int) (
                                            $line['product_id']
                                            ?? 0
                                        )
                                    );
                                    ?>
                                </select>
                            </div>

                            <div class="form-field quick-sale-quantity">
                                <label>Quantity</label>
                                <input
                                    type="number"
                                    min="0"
                                    step="1"
                                    inputmode="numeric"
                                    name="lines[<?= e($index) ?>][quantity]"
                                    value="<?= e(
                                        $line['quantity']
                                        ?? '0'
                                    ) ?>"
                                    required
                                >
                            </div>

                            <div class="quick-sale-price-note" aria-label="Calculated selling terms" hidden>
                                <span>Unit Price <strong><?= e($currency) ?> <span data-quick-unit>—</span></strong></span>
                                <span>Discount <strong><span data-quick-discount-percent>—</span>%</strong></span>
                                <span>Tax <strong><span data-quick-tax-percent>—</span>%</strong></span>
                                <span>Available <strong data-quick-available>—</strong></span>
                                <span>Gross <strong><?= e($currency) ?> <span data-quick-gross>—</span></strong></span>
                                <span>Discount Amount <strong><?= e($currency) ?> <span data-quick-discount-total>—</span></strong></span>
                                <span>Tax Amount <strong><?= e($currency) ?> <span data-quick-tax-total>—</span></strong></span>
                                <span class="quick-sale-line-total">Total <strong><?= e($currency) ?> <span data-quick-net>—</span></strong></span>
                                <small data-quick-warning hidden>Price is not configured for this SKU. Ask an administrator to update Pricelists.</small>
                            </div>

                            <button
                                class="btn btn-secondary btn-compact"
                                type="button"
                                data-quick-remove
                            >
                                Remove
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <template data-quick-template>
                    <div class="quick-sale-item" data-quick-line>
                        <div class="form-field"><label>Type</label><select data-field="product_family" data-variant-family><option value="">Legacy / Other</option><option value="mobile">Mobile</option><option value="mifi">MiFi</option></select></div>
                        <div class="form-field"><label>MiFi subtype</label><select data-field="mifi_subtype" data-variant-subtype><option value="">Select type first</option><option value="portable">Portable</option><option value="non_portable">Non-Portable</option></select></div>
                        <div class="form-field"><label>Brand</label><select data-field="brand_id" data-variant-brand><option value="">Select type first</option></select></div>
                        <div class="form-field"><label>Model</label><select data-field="model_id" data-variant-model><option value="">Select brand first</option></select></div>
                        <div class="form-field">
                            <label>Product</label>
                            <select data-field="product_id">
                                <?php $productOptions($products, 0); ?>
                            </select>
                        </div>

                        <div class="form-field quick-sale-quantity">
                            <label>Quantity</label>
                            <input
                                type="number"
                                min="0"
                                step="1"
                                inputmode="numeric"
                                value="0"
                                data-field="quantity"
                                required
                            >
                        </div>

                        <div class="quick-sale-price-note" aria-label="Calculated selling terms" hidden>
                            <span>Unit Price <strong><?= e($currency) ?> <span data-quick-unit>—</span></strong></span>
                            <span>Discount <strong><span data-quick-discount-percent>—</span>%</strong></span>
                            <span>Tax <strong><span data-quick-tax-percent>—</span>%</strong></span>
                            <span>Available <strong data-quick-available>—</strong></span>
                            <span>Gross <strong><?= e($currency) ?> <span data-quick-gross>—</span></strong></span>
                            <span>Discount Amount <strong><?= e($currency) ?> <span data-quick-discount-total>—</span></strong></span>
                            <span>Tax Amount <strong><?= e($currency) ?> <span data-quick-tax-total>—</span></strong></span>
                            <span class="quick-sale-line-total">Total <strong><?= e($currency) ?> <span data-quick-net>—</span></strong></span>
                            <small data-quick-warning hidden>Price is not configured for this SKU. Ask an administrator to update Pricelists.</small>
                        </div>

                        <button
                            class="btn btn-secondary btn-compact"
                            type="button"
                            data-quick-remove
                        >
                            Remove
                        </button>
                    </div>
                </template>
            </section>

            <div class="quick-sale-submit">
                <button
                    class="btn btn-primary"
                    type="submit"
                >
                    Send to Manager
                </button>
            </div>
        </form>

        <script
            src="<?= e(assetUrl('js/quick-sale.js')) ?>"
            defer
        ></script>

        <section class="card quick-sale-card quick-sale-history">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Completed</p>
                    <h3>Sales History</h3>
                    <p>
                        Closed Quick Sales are kept here for
                        receipt and audit reference.
                    </p>
                </div>

                <span class="badge badge-neutral">
                    <?= e(count($history)) ?>
                </span>
            </div>

            <?php if ($history === []): ?>

                <p class="quick-sale-history-empty">
                    No completed Quick Sales yet.
                </p>

            <?php else: ?>

                <div class="quick-sale-history-list">
                    <?php foreach ($history as $sale): ?>

                        <div class="quick-sale-history-row">
                            <div>
                                <strong>
                                    <a href="<?= e(appBasePath()) ?>/sales/quick-sale/<?= e($sale['quick_sale_id']) ?>">
                                    <?= e(
                                        $sale['quotation_number']
                                        ?? 'Quick Sale'
                                    ) ?></a>
                                </strong>

                                <span>
                                    <?= e(
                                        $sale['team_name']
                                        ?? ''
                                    ) ?>
                                    ·
                                    <?= e(
                                        $sale['warehouse_name']
                                        ?? ''
                                    ) ?>
                                </span>
                            </div>

                            <div class="quick-sale-history-stat">
                                <span>Sold</span>
                                <strong>

                                    <?= e(number_format(
                                        (float) (
                                            $sale['sold_quantity']
                                            ?? 0
                                        ),
                                        3,
                                        '.',
                                        ''
                                    )) ?>
                                </strong>
                            </div>

                            <div class="quick-sale-history-stat">
                                <span>Receipt</span>
<?php if (!empty($sale['has_evidence']) && !empty($sale['report_id'])): ?>
<a href="<?= e(appBasePath()) ?>/sales/quick-sale/<?= e($sale['quick_sale_id']) ?>/reports/<?= e($sale['report_id']) ?>/evidence" target="_blank" rel="noopener">View Receipt</a>
<?php endif; ?>
                                <strong>

                                    <?= e(
                                        $sale['invoice_reference']
                                        ?? '—'
                                    ) ?>
                                </strong>
                            </div>

                            <div class="quick-sale-history-stat">
                                <span>Closed</span>
                                <strong>

                                    <?= e(
                                        !empty($sale['reviewed_at'])
                                            ? date(
                                                'd M Y H:i',
                                                strtotime(
                                                    (string)
                                                    $sale['reviewed_at']
                                                )
                                            )
                                            : date(
                                                'd M Y H:i',
                                                strtotime(
                                                    (string)
                                                    $sale['closed_at']
                                                )
                                            )
                                    ) ?>
                                </strong>
                            </div>

                            <span class="badge badge-success">
                                Closed
                            </span>
                        </div>

                    <?php endforeach; ?>
                </div>

            <?php endif; ?>
        </section>

    <?php endif; ?>
</div>
