<?php

declare(strict_types=1);

$detail = is_array($data['quickSaleDetail'] ?? null)
    ? $data['quickSaleDetail']
    : [];

$sale = is_array($detail['quickSale'] ?? null)
    ? $detail['quickSale']
    : [];

$quotation = is_array($detail['quotation'] ?? null)
    ? $detail['quotation']
    : [];

$lines = is_array($quotation['lines'] ?? null)
    ? $quotation['lines']
    : [];

$locations = is_array($detail['locations'] ?? null)
    ? $detail['locations']
    : [];

$availability = is_array($detail['availability'] ?? null)
    ? $detail['availability']
    : [];

$errors = is_array($data['errors'] ?? null)
    ? $data['errors']
    : [];

$notice = is_array($data['notice'] ?? null)
    ? $data['notice']
    : null;

$canConfirm = !empty($detail['canConfirm']);

$canReport = !empty($detail['canReport']);

$reportLines = is_array($detail['reportLines'] ?? null)
    ? $detail['reportLines']
    : [];

$canViewReport = !empty($detail['canViewReport']);

$canReviewReport = !empty($detail['canReviewReport']);

$isAuthorizedReviewer =
    !empty($detail['isAuthorizedReviewer']);

$managerReport = is_array($detail['managerReport'] ?? null)
    ? $detail['managerReport']
    : null;

$managerReportStatus = is_array($managerReport)
    ? (string) ($managerReport['status'] ?? '')
    : '';

$managerReportLines = is_array(
    $detail['managerReportLines'] ?? null
)
    ? $detail['managerReportLines']
    : [];

$isManager = !empty($detail['isManager']);

$isOwner = !empty($detail['isOwner']);

$status = (string) ($sale['status'] ?? '');

$isReportCorrection =
    $isOwner
    && $status === 'reported'
    && is_array($managerReport)
    && (string) ($managerReport['status'] ?? '')
        === 'correction_required';

$statusLabel = match ($status) {
    'submitted' => 'Waiting for manager',
    'allocated' => 'Ready to sell',
    'reported' => 'Awaiting manager confirmation',
    'closed' => 'Closed',
    'sold' => 'Completed',
    'return_requested' => 'Return requested',
    'returned' => 'Returned',
    'cancelled' => 'Rejected / Cancelled',
    default => strtoupper(str_replace('_', ' ', $status)),
};
?>

<div class="sales-workspace quick-sale-shell">

    <div class="page-actions">
        <a
            class="btn btn-secondary"
            href="/office_app/public/sales/quick-sale"
        >
            Back
        </a>
    </div>

    <?php if ($notice !== null): ?>
        <div class="alert alert-success">
            <?= e($notice['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger">
            <?= e(
                is_array($error)
                    ? (string) ($error['message'] ?? 'Unable to continue.')
                    : (string) $error
            ) ?>
        </div>
    <?php endforeach; ?>

    <header class="quick-sale-header">
        <div>
            <p class="eyebrow">Quick Sale</p>
            <h2>
                <?= e(
                    $sale['quotation_number']
                    ?? 'Quick Sale'
                ) ?>
            </h2>

            <p>
                <?= e($sale['agent_name'] ?? '') ?>
                ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â·
                <?= e($sale['team_name'] ?? '') ?>
            </p>
        </div>

        <span class="badge badge-neutral">
            <?= e($statusLabel) ?>
        </span>
    </header>

    <section class="quick-sale-context">
        <div class="quick-sale-context-item">
            <span>Sent by DSA / DSP</span>
            <strong>
                <?= e($sale['agent_name'] ?? '') ?>
            </strong>
        </div>

        <div class="quick-sale-context-item">
            <span>Shop / Team</span>
            <strong>
                <?= e($sale['team_name'] ?? '') ?>
            </strong>
        </div>

        <div class="quick-sale-context-item">
            <span>Shop stock</span>
            <strong>
                <?= e($sale['warehouse_name'] ?? '') ?>
            </strong>
        </div>

        <div class="quick-sale-context-item">
            <span>Manager</span>
            <strong>
                <?= e($sale['manager_name'] ?? '') ?>
            </strong>
        </div>
    </section>

    <?php if ($canConfirm): ?>

        <form
            method="post"
            action="/office_app/public/sales/quick-sale/<?= e(
                $sale['quick_sale_id']
            ) ?>/confirm"
        >
            <?= csrfField() ?>

            <section class="card quick-sale-card">
                <h3>Requested products</h3>

                <?php foreach ($lines as $index => $line): ?>
                    <div class="quick-sale-manager-line">

                        <div class="quick-sale-manager-product">
                            <strong>
                                <?= e(
                                    ($line['sku'] ?? '')
                                    . ' - '
                                    . (
                                        $line['product_name']
                                        ?? $line['description']
                                        ?? ''
                                    )
                                ) ?>
                            </strong>

                            <span>
                                Qty:
                                <?= e($line['quantity']) ?>
                                ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¾Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â¦ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Â¦Ãƒâ€šÃ‚Â¡ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â·
                                Unit:
                                <?= e(
                                    $quotation['currency']
                                    . ' '
                                    . number_format(
                                        (float) $line['unit_price'],
                                        2
                                    )
                                ) ?>
                            </span>
                        </div>

                        <div class="form-field">
                            <label>Discount</label>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                name="discount_amount[<?= e($index) ?>]"
                                value="<?= e(
                                    $line['discount_amount'] ?? 0
                                ) ?>"
                            >
                        </div>

                        <div class="form-field">
                            <label>Tax %</label>
                            <input
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                name="tax_rate[<?= e($index) ?>]"
                                value="<?= e(
                                    $line['tax_rate'] ?? 0
                                ) ?>"
                            >
                        </div>

                    </div>
                <?php endforeach; ?>
            </section>

            <section class="card quick-sale-card">
                <h3>Stock allocation</h3>

                <?php if ($locations === []): ?>
                    <div class="alert alert-danger">
                        No authorized source location is available
                        inside this shop warehouse.
                    </div>
                <?php else: ?>

                    <div class="form-field">
                        <label>Source location</label>

                        <select
                            name="source_location_id"
                            required
                        >
                            <option value="">
                                Select stock location
                            </option>

                            <?php foreach ($locations as $location): ?>
                                <option value="<?= e(
                                    $location['location_id']
                                ) ?>">
                                    <?= e(
                                        ($location['code'] ?? '')
                                        . ' - '
                                        . ($location['name'] ?? '')
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                <?php endif; ?>

                <?php if ($availability !== []): ?>
                    <div class="quick-sale-stock-list">

                        <?php foreach ($availability as $stock): ?>
                            <div class="quick-sale-stock-item">
                                <span>
                                    <?= e(
                                        $stock['sku']
                                        . ' - '
                                        . $stock['name']
                                    ) ?>
                                </span>

                                <strong>
                                    Available:
                                    <?= e(
                                        $stock['quantity_available']
                                        ?? 0
                                    ) ?>
                                </strong>
                            </div>
                        <?php endforeach; ?>

                    </div>
                <?php endif; ?>
            </section>

            <div class="quick-sale-submit">
                <button
                    type="submit"
                    class="btn btn-primary"
                    <?= $locations === [] ? 'disabled' : '' ?>
                >
                    Confirm Sale
                </button>
            </div>
        </form>

    <?php else: ?>

        <section class="card quick-sale-card">
            <h3>Products</h3>

            <?php foreach ($lines as $line): ?>
                <div class="quick-sale-read-line">
                    <div>
                        <strong>
                            <?= e(
                                $line['product_name']
                                ?? $line['description']
                                ?? ''
                            ) ?>
                        </strong>

                        <span>
                            Qty <?= e($line['quantity']) ?>
                        </span>
                    </div>

                    <strong>
                        <?= e(
                            $quotation['currency']
                            . ' '
                            . number_format(
                                (float) $line['line_total'],
                                2
                            )
                        ) ?>
                    </strong>
                </div>
            <?php endforeach; ?>

            <div class="table-summary">
                <span>
                    Tax
                    <?= e(
                        number_format(
                            (float) ($quotation['tax_amount'] ?? 0),
                            2
                        )
                    ) ?>
                </span>

                <strong>
                    Total
                    <?= e(
                        $quotation['currency']
                        . ' '
                        . number_format(
                            (float) (
                                $quotation['total_amount']
                                ?? 0
                            ),
                            2
                        )
                    ) ?>
                </strong>
            </div>
        </section>

        <?php if ($status === 'allocated'): ?>
            <div class="alert alert-success">
                Stock is allocated. This sale is ready to be reported.
            </div>
        <?php endif; ?>

        <?php if ($canReport): ?>

            <?php if ($isReportCorrection): ?>
                <section class="card quick-sale-card quick-sale-correction-card">
                    <div class="quick-sale-correction-heading">
                        <div>
                            <p class="eyebrow">Action required</p>
                            <h3>Correct and Resubmit Sales Report</h3>
                            <p>
                                Your Shop Manager returned this report.
                                Correct the invoice / receipt information
                                and submit a replacement attachment.
                            </p>
                        </div>

                        <span class="badge badge-warning">
                            Correction required
                        </span>
                    </div>

                    <div class="alert alert-danger">
                        <strong>Manager reason:</strong>
                        <?= e(
                            (string) (
                                $managerReport['review_note']
                                ?? 'Please correct and resubmit the report.'
                            )
                        ) ?>
                    </div>

                    <?php if (
                        !empty($managerReport['evidence_original_name'])
                        && !empty($managerReport['report_id'])
                    ): ?>
                        <div class="quick-sale-own-evidence">
                            <span>Previous receipt</span>

                            <strong>
                                <?= e(
                                    $managerReport[
                                        'evidence_original_name'
                                    ]
                                ) ?>
                            </strong>

                            <a
                                class="btn btn-secondary"
                                target="_blank"
                                rel="noopener"
                                href="/office_app/public/sales/quick-sale/<?= e(
                                    $sale['quick_sale_id']
                                ) ?>/reports/<?= e(
                                    $managerReport['report_id']
                                ) ?>/evidence"
                            >
                                View Returned Receipt
                            </a>
                        </div>
                    <?php endif; ?>

                    <p class="form-help">
                        The returned receipt is kept for audit.
                        Your corrected submission creates a new report
                        and does not overwrite the previous evidence.
                    </p>
                </section>
            <?php endif; ?>

            <form
                method="post"
                enctype="multipart/form-data"
                action="/office_app/public/sales/quick-sale/<?= e(
                    $sale['quick_sale_id']
                ) ?>/report"
                class="quick-sale-report-form"
                data-quick-sale-report
            >
                <?= csrfField() ?>

                <section class="card quick-sale-card">
                    <div class="quick-sale-report-heading">
                        <div>
                            <p class="eyebrow">
                                <?= $isReportCorrection
                                    ? 'Correction'
                                    : 'After the sale' ?>
                            </p>

                            <h3>
                                <?= $isReportCorrection
                                    ? 'Resubmit Sales Report'
                                    : 'Report Sale' ?>
                            </h3>

                            <p>
                                <?php if ($isReportCorrection): ?>
                                    Review the quantities and replace the
                                    incorrect invoice / receipt evidence.
                                <?php else: ?>
                                    Enter what was sold and what came back.
                                    Your Shop Manager will confirm the report.
                                <?php endif; ?>
                            </p>
                        </div>

                        <span class="badge badge-neutral">
                            <?= e(count($reportLines)) ?>
                            product<?= count($reportLines) === 1 ? '' : 's' ?>
                        </span>
                    </div>

                    <div class="quick-sale-report-lines">

                        <?php foreach ($reportLines as $reportLine): ?>
                            <?php
                            $allocated = max(
                                0,
                                round(
                                    (float) (
                                        $reportLine['reserved_quantity']
                                        ?? 0
                                    )
                                    - (float) (
                                        $reportLine['completed_quantity']
                                        ?? 0
                                    ),
                                    3
                                )
                            );

                            $lineId =
                                (int) $reportLine['picking_line_id'];
                            ?>

                            <div
                                class="quick-sale-report-line"
                                data-report-line
                                data-allocated="<?= e($allocated) ?>"
                            >
                                <div class="quick-sale-report-product">
                                    <strong>
                                        <?= e(
                                            ($reportLine['sku'] ?? '')
                                            . ' - '
                                            . (
                                                $reportLine['product_name']
                                                ?? ''
                                            )
                                        ) ?>
                                    </strong>

                                    <span>
                                        Allocated
                                        <b><?= e(
                                            number_format(
                                                $allocated,
                                                3,
                                                '.',
                                                ''
                                            )
                                        ) ?></b>
                                    </span>
                                </div>

                                <div class="form-field">
                                    <label>Sold</label>
                                    <input
                                        type="number"
                                        min="0"
                                        max="<?= e($allocated) ?>"
                                        step="0.001"
                                        inputmode="decimal"
                                        name="report_lines[<?= e(
                                            $lineId
                                        ) ?>][sold_quantity]"
                                        value="<?= e(
                                            number_format(
                                                $allocated,
                                                3,
                                                '.',
                                                ''
                                            )
                                        ) ?>"
                                        data-sold
                                        required
                                    >
                                </div>

                                <div class="form-field">
                                    <label>Returned</label>
                                    <input
                                        type="number"
                                        min="0"
                                        max="<?= e($allocated) ?>"
                                        step="0.001"
                                        inputmode="decimal"
                                        name="report_lines[<?= e(
                                            $lineId
                                        ) ?>][returned_quantity]"
                                        value="0.000"
                                        data-returned
                                        required
                                    >
                                </div>
                            </div>

                        <?php endforeach; ?>

                    </div>

                    <p class="form-help">
                        For every product:
                        <strong>Sold + Returned = Allocated</strong>
                    </p>
                </section>

                <section class="card quick-sale-card">
                    <div class="quick-sale-report-heading">
                        <div>
                            <p class="eyebrow">Sale evidence</p>
                            <h3>Invoice / Receipt</h3>
                            <p>
                                Required when at least one item was sold.
                            </p>
                        </div>
                    </div>

                    <div class="quick-sale-report-meta">

                        <div class="form-field">
                            <label>Invoice / Receipt No.</label>
                            <input
                                type="text"
                                name="invoice_reference"
                                maxlength="120"
                                autocomplete="off"
                                placeholder="Invoice or receipt number"
                                value="<?= e(
                                    $isReportCorrection
                                        ? (
                                            $managerReport[
                                                'invoice_reference'
                                            ]
                                            ?? ''
                                        )
                                        : ''
                                ) ?>"
                            >
                        </div>

                        <div class="form-field">
                            <label>Payment method</label>
                            <select name="payment_method">
                                <?php
                                $previousPayment = $isReportCorrection
                                    ? (string) (
                                        $managerReport['payment_method']
                                        ?? ''
                                    )
                                    : '';
                                ?>

                                <option value="">Select payment</option>

                                <option
                                    value="cash"
                                    <?= $previousPayment === 'cash'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Cash
                                </option>

                                <option
                                    value="bank_transfer"
                                    <?= $previousPayment === 'bank_transfer'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Bank transfer
                                </option>

                                <option
                                    value="mobile_money"
                                    <?= $previousPayment === 'mobile_money'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Mobile money
                                </option>

                                <option
                                    value="card"
                                    <?= $previousPayment === 'card'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Card
                                </option>

                                <option
                                    value="other"
                                    <?= $previousPayment === 'other'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Other
                                </option>
                            </select>
                        </div>

                        <div class="form-field">
                            <label>Payment reference</label>
                            <input
                                type="text"
                                name="payment_reference"
                                maxlength="120"
                                autocomplete="off"
                                placeholder="Optional for cash"
                                value="<?= e(
                                    $isReportCorrection
                                        ? (
                                            $managerReport[
                                                'payment_reference'
                                            ]
                                            ?? ''
                                        )
                                        : ''
                                ) ?>"
                            >
                        </div>

                        <div class="form-field">
                            <label>Invoice attachment</label>
                            <input
                                type="file"
                                name="invoice_attachment"
                                accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg"
                            >
                            <?php if ($isReportCorrection): ?>
                                <small>
                                    Attach the corrected receipt / invoice.
                                    The previous attachment cannot be reused.
                                </small>
                            <?php endif; ?>

                            <small>
                                PDF, PNG or JPEG - maximum 10 MB
                            </small>
                        </div>

                    </div>

                    <div class="form-field quick-sale-report-note">
                        <label>Note</label>
                        <textarea
                            name="report_note"
                            rows="3"
                            maxlength="5000"
                            placeholder="Optional note for your manager"
                        ><?= e(
                            $isReportCorrection
                                ? (
                                    $managerReport['report_note']
                                    ?? ''
                                )
                                : ''
                        ) ?></textarea>
                    </div>
                </section>

                <div class="quick-sale-submit">
                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        <?= $isReportCorrection
                            ? 'Resubmit Corrected Report'
                            : 'Submit Sales Report' ?>
                    </button>
                </div>
            </form>

            <script>
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

                        sold.addEventListener('input', () => {
                            const soldValue =
                                clamp(
                                    Number(sold.value || 0),
                                    0,
                                    allocated
                                );

                            returned.value =
                                format(allocated - soldValue);
                        });

                        returned.addEventListener('input', () => {
                            const returnedValue =
                                clamp(
                                    Number(returned.value || 0),
                                    0,
                                    allocated
                                );

                            sold.value =
                                format(allocated - returnedValue);
                        });
                    });
            })();
            </script>

        <?php elseif ($canViewReport && $managerReport !== null): ?>

            <section class="card quick-sale-card">
                <div class="quick-sale-report-heading">
                    <div>
                        <p class="eyebrow">DSA / DSP sales report</p>

                        <h3>
                            <?= $managerReportStatus === 'correction_required'
                                ? 'Returned for Correction'
                                : 'Confirm Report' ?>
                        </h3>

                        <p>
                            <?php if (
                                $managerReportStatus === 'correction_required'
                            ): ?>
                                Waiting for the DSA/DSP to correct the
                                report and submit a replacement receipt.
                            <?php else: ?>
                                Check the quantities and sale evidence
                                before completing this sale.
                            <?php endif; ?>
                        </p>
                    </div>

                    <span class="badge badge-warning">
                        <?php if (
                            $managerReportStatus === 'correction_required'
                        ): ?>
                            Waiting for DSA / DSP
                        <?php else: ?>
                            <?= $isManager
                                ? 'Manager review'
                                : 'Authorized view' ?>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="quick-sale-manager-report-summary">
                    <div>
                        <span>Reported by</span>
                        <strong>
                            <?= e(
                                $managerReport['reported_by_name']
                                ?? $sale['agent_name']
                                ?? ''
                            ) ?>
                        </strong>
                    </div>

                    <div>
                        <span>Invoice / Receipt</span>
                        <strong>
                            <?= e(
                                $managerReport['invoice_reference']
                                ?? 'Not applicable'
                            ) ?>
                        </strong>
                    </div>

                    <div>
                        <span>Payment method</span>
                        <strong>
                            <?= e(
                                !empty($managerReport['payment_method'])
                                    ? ucwords(
                                        str_replace(
                                            '_',
                                            ' ',
                                            (string)
                                            $managerReport['payment_method']
                                        )
                                    )
                                    : 'Not applicable'
                            ) ?>
                        </strong>
                    </div>

                    <div>
                        <span>Payment reference</span>
                        <strong>
                            <?= e(
                                $managerReport['payment_reference']
                                ?? 'Not provided'
                            ) ?>
                        </strong>
                    </div>
                </div>
            </section>

            <section class="card quick-sale-card">
                <div class="quick-sale-report-heading">
                    <div>
                        <p class="eyebrow">Reported quantities</p>
                        <h3>Sold and Returned</h3>
                    </div>

                    <span class="badge badge-neutral">
                        <?= e(count($managerReportLines)) ?>
                        product<?= count($managerReportLines) === 1
                            ? ''
                            : 's' ?>
                    </span>
                </div>

                <div class="quick-sale-manager-report-lines">
                    <?php foreach ($managerReportLines as $reportLine): ?>

                        <div class="quick-sale-manager-report-line">
                            <div class="quick-sale-report-product">
                                <strong>
                                    <?= e(
                                        ($reportLine['sku'] ?? '')
                                        . ' - '
                                        . ($reportLine['product_name'] ?? '')
                                    ) ?>
                                </strong>
                            </div>

                            <div>
                                <span>Allocated</span>
                                <strong>
                                    <?= e(number_format(
                                        (float)
                                        ($reportLine['allocated_quantity'] ?? 0),
                                        3,
                                        '.',
                                        ''
                                    )) ?>
                                </strong>
                            </div>

                            <div>
                                <span>Sold</span>
                                <strong>
                                    <?= e(number_format(
                                        (float)
                                        ($reportLine['sold_quantity'] ?? 0),
                                        3,
                                        '.',
                                        ''
                                    )) ?>
                                </strong>
                            </div>

                            <div>
                                <span>Returned</span>
                                <strong>
                                    <?= e(number_format(
                                        (float)
                                        ($reportLine['returned_quantity'] ?? 0),
                                        3,
                                        '.',
                                        ''
                                    )) ?>
                                </strong>
                            </div>
                        </div>

                    <?php endforeach; ?>
                </div>
            </section>

            <section class="card quick-sale-card">
                <div class="quick-sale-report-heading">
                    <div>
                        <p class="eyebrow">Sale evidence</p>
                        <h3>Receipt / Invoice Evidence</h3>
                    </div>
                </div>

                <div class="quick-sale-manager-report-summary">
                    <div>
                        <span>Attachment</span>

                        <?php if (
                            !empty($managerReport['evidence_original_name'])
                            && !empty($managerReport['report_id'])
                        ): ?>
                            <div class="quick-sale-evidence-action">
                                <strong>
                                    <?= e(
                                        $managerReport[
                                            'evidence_original_name'
                                        ]
                                    ) ?>
                                </strong>

                                <a
                                    class="btn btn-secondary"
                                    target="_blank"
                                    rel="noopener"
                                    href="/office_app/public/sales/quick-sale/<?= e(
                                        $sale['quick_sale_id']
                                    ) ?>/reports/<?= e(
                                        $managerReport['report_id']
                                    ) ?>/evidence"
                                >
                                    View Receipt
                                </a>
                            </div>
                        <?php else: ?>
                            <strong>No attachment</strong>
                        <?php endif; ?>
                    </div>

                    <div>
                        <span>Report status</span>
                        <strong>
                            <?= match ($managerReportStatus) {
                                'correction_required' =>
                                    'Returned for Correction',
                                'confirmed' => 'Confirmed',
                                default => 'Submitted',
                            } ?>
                        </strong>
                    </div>
                </div>

                <?php if (!empty($managerReport['report_note'])): ?>
                    <div class="quick-sale-manager-report-note">
                        <span>DSA / DSP note</span>
                        <p><?= e($managerReport['report_note']) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (
                    $managerReportStatus === 'correction_required'
                ): ?>

                    <div class="alert alert-warning">
                        <strong>Returned to DSA / DSP for correction.</strong>

                        <?php if (
                            !empty($managerReport['review_note'])
                        ): ?>
                            <br>
                            Reason:
                            <?= e($managerReport['review_note']) ?>
                        <?php endif; ?>

                        <br>
                        Stock remains reserved. The DSA/DSP must submit
                        a corrected report and replacement receipt before
                        manager confirmation can continue.
                    </div>

                <?php else: ?>

                    <div class="alert alert-info">
                        Review is ready. Stock remains reserved and no final
                        customer invoice is created until the Shop Manager
                        confirms the report.
                    </div>

                <?php endif; ?>

                <?php if ($canReviewReport): ?>

                    <form
                        method="post"
                        action="/office_app/public/sales/quick-sale/<?= e(
                            $sale['quick_sale_id']
                        ) ?>/reports/<?= e(
                            $managerReport['report_id']
                        ) ?>/confirm"
                        class="quick-sale-confirm-report-form"
                    >
                        <?= csrfField() ?>

                        <div class="quick-sale-final-confirm">
                            <div>
                                <strong>Final Manager Confirmation</strong>
                                <p>
                                    Confirming completes the sold stock,
                                    releases any unsold reserved quantity,
                                    creates the delivered customer invoice
                                    when quantity was sold, and closes this
                                    Quick Sale.
                                </p>
                            </div>

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                Confirm Sales Report
                            </button>
                        </div>
                    </form>

                    <form
                        method="post"
                        action="/office_app/public/sales/quick-sale/<?= e(
                            $sale['quick_sale_id']
                        ) ?>/reports/<?= e(
                            $managerReport['report_id']
                        ) ?>/correction"
                        class="quick-sale-correction-form"
                    >
                        <?= csrfField() ?>

                        <div class="form-field">
                            <label>
                                Return for Correction
                            </label>

                            <textarea
                                name="review_note"
                                rows="3"
                                maxlength="5000"
                                required
                                placeholder="Explain exactly what is wrong, for example: wrong receipt attached, incorrect invoice number, or incorrect payment reference."
                            ></textarea>

                            <small>
                                This does not cancel the sale and does not
                                release the reserved stock.
                            </small>
                        </div>

                        <div class="quick-sale-correction-actions">
                            <button
                                type="submit"
                                class="btn btn-secondary"
                            >
                                Return to DSA / DSP
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>

        <?php elseif ($isOwner && $status === 'reported'): ?>

            <section class="card quick-sale-card">
                <div class="quick-sale-report-waiting">
                    <div class="quick-sale-report-icon" aria-hidden="true"></div>

                    <div>
                        <p class="eyebrow">Report submitted</p>
                        <h3>Waiting for Manager Confirmation</h3>
                        <p>
                            Your sales report has been sent to your
                            Shop Manager. No further action is needed
                            until it is reviewed.
                        </p>

                        <?php if (
                            is_array($managerReport)
                            && !empty($managerReport['report_id'])
                            && !empty(
                                $managerReport[
                                    'evidence_original_name'
                                ]
                            )
                        ): ?>
                            <div class="quick-sale-own-evidence">
                                <span>Submitted receipt</span>
                                <strong>
                                    <?= e(
                                        $managerReport[
                                            'evidence_original_name'
                                        ]
                                    ) ?>
                                </strong>

                                <a
                                    class="btn btn-secondary"
                                    target="_blank"
                                    rel="noopener"
                                    href="/office_app/public/sales/quick-sale/<?= e(
                                        $sale['quick_sale_id']
                                    ) ?>/reports/<?= e(
                                        $managerReport['report_id']
                                    ) ?>/evidence"
                                >
                                    View My Receipt
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

        <?php endif; ?>

    <?php endif; ?>
</div>