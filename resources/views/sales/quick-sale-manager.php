<?php

declare(strict_types=1);

$quick = is_array($data['quickSale'] ?? null)
    ? $data['quickSale']
    : [];

$queue = is_array($quick['queue'] ?? null)
    ? $quick['queue']
    : [];

$waiting = is_array($quick['waiting'] ?? null)
    ? $quick['waiting']
    : [];

$history = is_array($quick['history'] ?? null)
    ? $quick['history']
    : [];

$notice = is_array($data['notice'] ?? null)
    ? $data['notice']
    : null;
?>

<div class="sales-workspace quick-sale-shell">

    <?php if ($notice !== null): ?>
        <div class="alert alert-success">
            <?= e($notice['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <header class="quick-sale-header">
        <div>
            <p class="eyebrow">Shop Manager</p>
            <h2>Quick Sales</h2>
            <p>
                Confirm pricing adjustments and allocate
                stock for your DSA/DSP team.
            </p>
        </div>
    </header>

    <?php if ($queue === []): ?>

        <section class="card quick-sale-card">
            <h3>No manager action required</h3>
            <p>
                New requests and resubmitted sales reports will appear here automatically.
            </p>
        </section>

    <?php else: ?>

        <div class="quick-sale-manager-list">
            <?php foreach ($queue as $sale): ?>

                <a
                    class="quick-sale-manager-item"
                    href="/office_app/public/sales/quick-sale/<?= e(
                        $sale['quick_sale_id']
                    ) ?>"
                >
                    <div>
                        <strong>
                            <?= e($sale['agent_name']) ?>
                        </strong>

                        <span>
                            <?= e($sale['team_name']) ?>
                            Ãƒâ€š-
                            <?= e($sale['warehouse_name']) ?>
                        </span>
                    </div>

                    <div class="quick-sale-manager-total">
                        <strong>
                            <?= e(
                                $sale['currency']
                                . ' '
                                . number_format(
                                    (float) $sale['total_amount'],
                                    2
                                )
                            ) ?>
                        </strong>

                        <span class="badge badge-neutral">
                            <?= e(
                                $sale['status'] === 'submitted'
                                    ? 'Allocation Request'
                                    : 'Report Review'
                            ) ?>
                        </span>
                    </div>
                </a>

            <?php endforeach; ?>
        </div>

    <?php endif; ?>

    <?php if ($waiting !== []): ?>

        <section class="card quick-sale-card quick-sale-waiting-section">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">In progress</p>
                    <h3>Waiting on DSA / DSP</h3>
                    <p>
                        Reports returned for correction.
                        Open any record to review its current status.
                    </p>
                </div>

                <span class="badge badge-warning">
                    <?= e(count($waiting)) ?>
                </span>
            </div>

            <div class="quick-sale-manager-list">
                <?php foreach ($waiting as $sale): ?>

                    <a
                        class="quick-sale-manager-item"
                        href="/office_app/public/sales/quick-sale/<?= e(
                            $sale['quick_sale_id']
                        ) ?>"
                    >
                        <div>
                            <strong>
                                <a
                                    class="quick-sale-history-detail-link"
                                    href="/office_app/public/sales/quick-sale/<?= e(
                                        $sale['quick_sale_id']
                                    ) ?>"
                                >
                                    <?= e(
                                        $sale['quotation_number']
                                        ?? 'Quick Sale'
                                    ) ?>
                                </a>
                            </strong>

                            <span>
                                <?= e($sale['agent_name'] ?? '') ?>
                                /
                                <?= e($sale['team_name'] ?? '') ?>
                                /
                                <?= e($sale['warehouse_name'] ?? '') ?>
                            </span>

                            <?php if (!empty($sale['review_note'])): ?>
                                <span class="quick-sale-waiting-reason">
                                    Reason:
                                    <?= e($sale['review_note']) ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="quick-sale-manager-total">
                            <strong>
                                <?= e(
                                    ($sale['currency'] ?? '')
                                    . ' '
                                    . number_format(
                                        (float) (
                                            $sale['total_amount']
                                            ?? 0
                                        ),
                                        2
                                    )
                                ) ?>
                            </strong>

                            <span class="badge badge-warning">
                                Returned for Correction
                            </span>
                        </div>
                    </a>

                <?php endforeach; ?>
            </div>
        </section>

    <?php endif; ?>

    <section class="card quick-sale-card quick-sale-history">
        <div class="section-heading">
            <div>
                <p class="eyebrow">Completed</p>
                <h3>Sales History</h3>
                <p>
                    Quick Sales already confirmed and closed.
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
                                <a
                                    class="quick-sale-history-detail-link"
                                    href="/office_app/public/sales/quick-sale/<?= e(
                                        $sale['quick_sale_id']
                                    ) ?>"
                                >
                                    <?= e(
                                        $sale['quotation_number']
                                        ?? 'Quick Sale'
                                    ) ?>
                                </a>
                            </strong>

                            <span>
                                <?= e($sale['agent_name'] ?? '') ?>
                                -
                                <?= e($sale['team_name'] ?? '') ?>
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
                                    ?? '-'
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

                        <div class="quick-sale-history-actions">
                            <?php if (
                                !empty($sale['report_id'])
                                && !empty($sale['invoice_reference'])
                            ): ?>
                                <a
                                    class="btn btn-secondary quick-sale-history-receipt-link"
                                    href="/office_app/public/sales/quick-sale/<?= e(
                                        $sale['quick_sale_id']
                                    ) ?>/reports/<?= e(
                                        $sale['report_id']
                                    ) ?>/evidence"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    View Receipt
                                </a>
                            <?php endif; ?>

                            <span class="badge badge-success">
                                Closed
                            </span>
                        </div>
                    </div>

                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </section>
</div>