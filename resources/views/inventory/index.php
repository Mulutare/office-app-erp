<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null)
    ? $data
    : [];

$inventorySummary = is_array(
    $data['inventorySummary'] ?? null
)
    ? $data['inventorySummary']
    : [
        'warehouseCount' => 0,
        'stockItemCount' => 0,
        'totalQuantity' => 0,
        'pendingReceiptCount' => 0,
    ];

$stockBalances = is_array(
    $data['stockBalances'] ?? null
)
    ? $data['stockBalances']
    : [];

$goodsReceipts = is_array(
    $data['goodsReceipts'] ?? null
)
    ? $data['goodsReceipts']
    : [];
?>

<section class="page-section">
    <div class="section-heading">
        <div>
            <span class="eyebrow">Inventory control</span>

            <h2>Inventory workspace</h2>

            <p>
                Monitor warehouses, stock availability,
                inventory value and incoming goods.
            </p>
        </div>
    </div>

    <div class="card-grid">
        <article class="card">
            <p class="metric-label">
                Warehouses
            </p>

            <p class="metric-value">
                <?= (int) (
                    $inventorySummary['warehouseCount']
                    ?? 0
                ) ?>
            </p>

            <p class="text-muted">
                Active storage facilities
            </p>
        </article>

        <article class="card">
            <p class="metric-label">
                Stock balances
            </p>

            <p class="metric-value">
                <?= (int) (
                    $inventorySummary['stockItemCount']
                    ?? 0
                ) ?>
            </p>

            <p class="text-muted">
                Product-location balances
            </p>
        </article>

        <article class="card">
            <p class="metric-label">
                Total quantity
            </p>

            <p class="metric-value">
                <?= e(number_format(
                    (float) (
                        $inventorySummary['totalQuantity']
                        ?? 0
                    ),
                    3
                )) ?>
            </p>

            <p class="text-muted">
                Units currently on hand
            </p>
        </article>

        <article class="card">
            <p class="metric-label">
                Pending receipts
            </p>

            <p class="metric-value">
                <?= (int) (
                    $inventorySummary[
                        'pendingReceiptCount'
                    ]
                    ?? 0
                ) ?>
            </p>

            <p class="text-muted">
                Awaiting completion or posting
            </p>
        </article>
    </div>
</section>

<section class="page-section">
    <article class="card table-card">
        <div class="table-summary">
            <div>
                <h2 class="card-title">
                    Current stock
                </h2>

                <p class="text-muted">
                    Quantity on hand, reserved quantity,
                    available stock and average unit cost.
                </p>
            </div>

            <span class="badge badge-neutral">
                <?= count($stockBalances) ?>
                balance<?= count($stockBalances) === 1
                    ? ''
                    : 's' ?>
            </span>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Warehouse</th>
                        <th>Location</th>
                        <th>On hand</th>
                        <th>Reserved</th>
                        <th>Available</th>
                        <th>Average cost</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($stockBalances === []): ?>
                    <tr>
                        <td
                            colspan="8"
                            class="empty-state"
                        >
                            No inventory stock has been
                            recorded yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach (
                        $stockBalances as $stock
                    ): ?>
                        <tr>
                            <td>
                                <strong>
                                    <?= e((string) (
                                        $stock[
                                            'product_name'
                                        ]
                                        ?? (
                                            'Product #'
                                            . (
                                                $stock[
                                                    'product_id'
                                                ]
                                                ?? ''
                                            )
                                        )
                                    )) ?>
                                </strong>
                            </td>

                            <td>
                                <?= e((string) (
                                    $stock['sku'] ?? '—'
                                )) ?>
                            </td>

                            <td>
                                <?= e((string) (
                                    $stock[
                                        'warehouse_name'
                                    ]
                                    ?? (
                                        'Warehouse #'
                                        . (
                                            $stock[
                                                'warehouse_id'
                                            ]
                                            ?? ''
                                        )
                                    )
                                )) ?>
                            </td>

                            <td>
                                <?= e((string) (
                                    $stock['location_name']
                                    ?? (
                                        $stock['location_id']
                                        ?? '—'
                                    )
                                )) ?>
                            </td>

                            <td>
                                <?= e(number_format(
                                    (float) (
                                        $stock[
                                            'quantity_on_hand'
                                        ]
                                        ?? 0
                                    ),
                                    3
                                )) ?>
                            </td>

                            <td>
                                <?= e(number_format(
                                    (float) (
                                        $stock[
                                            'quantity_reserved'
                                        ]
                                        ?? 0
                                    ),
                                    3
                                )) ?>
                            </td>

                            <td>
                                <strong>
                                    <?= e(number_format(
                                        (float) (
                                            $stock[
                                                'quantity_available'
                                            ]
                                            ?? 0
                                        ),
                                        3
                                    )) ?>
                                </strong>
                            </td>

                            <td>
                                <?= e(number_format(
                                    (float) (
                                        $stock[
                                            'average_unit_cost'
                                        ]
                                        ?? 0
                                    ),
                                    2
                                )) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<section class="page-section">
    <article class="card table-card">
        <div class="table-summary">
            <div>
                <h2 class="card-title">
                    Recent goods receipts
                </h2>

                <p class="text-muted">
                    Latest inbound inventory documents
                    and their posting status.
                </p>
            </div>

            <span class="badge badge-neutral">
                <?= count($goodsReceipts) ?>
                receipt<?= count($goodsReceipts) === 1
                    ? ''
                    : 's' ?>
            </span>
        </div>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Receipt</th>
                        <th>Date</th>
                        <th>Currency</th>
                        <th>Status</th>
                        <th>Posted at</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($goodsReceipts === []): ?>
                    <tr>
                        <td
                            colspan="5"
                            class="empty-state"
                        >
                            No goods receipts have been
                            created yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach (
                        $goodsReceipts as $receipt
                    ): ?>
                        <?php
                        $status = strtolower(
                            (string) (
                                $receipt['status']
                                ?? 'unknown'
                            )
                        );

                        $badgeClass = match ($status) {
                            'posted',
                            'completed',
                            'approved' =>
                                'badge-success',

                            'pending',
                            'draft',
                            'submitted' =>
                                'badge-warning',

                            'cancelled',
                            'rejected' =>
                                'badge-danger',

                            default =>
                                'badge-neutral',
                        };
                        ?>

                        <tr>
                            <td>
                                <strong>
                                    <?= e((string) (
                                        $receipt[
                                            'receipt_number'
                                        ]
                                        ?? '—'
                                    )) ?>
                                </strong>
                            </td>

                            <td>
                                <?= e((string) (
                                    $receipt[
                                        'receipt_date'
                                    ]
                                    ?? '—'
                                )) ?>
                            </td>

                            <td>
                                <?= e((string) (
                                    $receipt['currency']
                                    ?? '—'
                                )) ?>
                            </td>

                            <td>
                                <span class="badge <?= e(
                                    $badgeClass
                                ) ?>">
                                    <?= e(ucfirst($status)) ?>
                                </span>
                            </td>

                            <td>
                                <?= e((string) (
                                    $receipt['posted_at']
                                    ?? 'Not posted'
                                )) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>