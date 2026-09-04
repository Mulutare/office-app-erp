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
                                href="/office_app/public/sales/quick-sale/<?= e(
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
            action="/office_app/public/sales/quick-sale"
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
                            <div class="form-field">
                                <label>Product</label>
                                <select
                                    name="lines[<?= e($index) ?>][product_id]"
                                    required
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
                                    min="0.001"
                                    step="0.001"
                                    inputmode="decimal"
                                    name="lines[<?= e($index) ?>][quantity]"
                                    value="<?= e(
                                        $line['quantity']
                                        ?? '1'
                                    ) ?>"
                                    required
                                >
                            </div>

                            <div class="quick-sale-price-note">
                                Price: <strong>Automatic</strong>
                                <small>
                                    Final <?= e($currency) ?> price is
                                    recalculated by the server.
                                </small>
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
                        <div class="form-field">
                            <label>Product</label>
                            <select data-field="product_id" required>
                                <?php $productOptions($products, 0); ?>
                            </select>
                        </div>

                        <div class="form-field quick-sale-quantity">
                            <label>Quantity</label>
                            <input
                                type="number"
                                min="0.001"
                                step="0.001"
                                inputmode="decimal"
                                value="1"
                                data-field="quantity"
                                required
                            >
                        </div>

                        <div class="quick-sale-price-note">
                            Price: <strong>Automatic</strong>
                            <small>
                                Final <?= e($currency) ?> price is
                                recalculated by the server.
                            </small>
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
            src="/office_app/public/assets/js/quick-sale.js"
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

                        <a
                            class="quick-sale-history-row"
                            href="/office_app/public/sales/quick-sale/<?= e(
                                $sale['quick_sale_id']
                            ) ?>"
                        >
                            <div>
                                <strong>
                                    <?= e(
                                        $sale['quotation_number']
                                        ?? 'Quick Sale'
                                    ) ?>
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
                        </a>

                    <?php endforeach; ?>
                </div>

            <?php endif; ?>
        </section>

    <?php endif; ?>
</div>