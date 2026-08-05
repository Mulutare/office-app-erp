<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$receivableSummary = is_array(
    $data['receivableSummary'] ?? null
)
    ? $data['receivableSummary']
    : [];

$receivables = is_array(
    $data['receivables'] ?? null
)
    ? $data['receivables']
    : [];

$receivableTotal = (int) (
    $data['receivableTotal'] ?? 0
);

$receivableStatusOptions = is_array(
    $data['receivableStatusOptions'] ?? null
)
    ? $data['receivableStatusOptions']
    : [];

$receivableFilters = is_array(
    $data['receivableFilters'] ?? null
)
    ? $data['receivableFilters']
    : [];

$receivablePagination = is_array(
    $data['receivablePagination'] ?? null
)
    ? $data['receivablePagination']
    : [];
$recentReceipts = is_array(
    $data['recentReceipts'] ?? null
)
    ? $data['recentReceipts']
    : [];

$recentJournals = is_array(
    $data['recentJournals'] ?? null
)
    ? $data['recentJournals']
    : [];
$requests = is_array(
    $data['requests'] ?? null
)
    ? $data['requests']
    : [];
$summary = is_array($data['summary'] ?? null)
    ? $data['summary']
    : [];
$statusOptions = is_array(
    $data['statusOptions'] ?? null
)
    ? $data['statusOptions']
    : [];
$filters = is_array($data['filters'] ?? null)
    ? $data['filters']
    : [];
$pagination = is_array(
    $data['pagination'] ?? null
)
    ? $data['pagination']
    : [];
$totalRequests = (int) (
    $pagination['total'] ?? 0
);

$formatDate = static function (mixed $value): string {
    if (!is_string($value) || trim($value) === '') {
        return 'Not recorded';
    }

    $timestamp = strtotime($value);

    return $timestamp === false
        ? $value
        : date('M j, Y', $timestamp);
};

function financeDashboardUrl(
    array $filters,
    array $overrides = []
): string {
    $query = array_merge(
        $filters,
        $overrides
    );

    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }

    return '/office_app/public/finance'
        . ($query === []
            ? ''
            : '?' . http_build_query($query));
}
?>

<section
    class="finance-overview"
    aria-labelledby="finance-overview-title"
>
    <div class="section-heading">
        <div>
            <h2 id="finance-overview-title">
                Financial Overview
            </h2>
            <p>
                Sales receivables and collections,
                separated by currency.
            </p>
        </div>
    </div>

    <?php if ($receivableSummary === []): ?>
        <article class="card empty-state">
            No Sales receivables have been posted yet.
        </article>
    <?php else: ?>
        <?php foreach (
            $receivableSummary as $currencySummary
        ): ?>
            <?php
            $currency = strtoupper((string) (
                $currencySummary['currency'] ?? ''
            ));
            ?>

            <div class="section-heading">
                <strong><?= e($currency) ?></strong>
            </div>

            <div class="finance-summary-grid">
                <article class="card finance-summary-card">
                    <span>Outstanding</span>
                    <strong>
                        <?= e(
                            $currency . ' ' .
                            number_format(
                                (float) (
                                    $currencySummary[
                                        'total_outstanding'
                                    ] ?? 0
                                ),
                                2
                            )
                        ) ?>
                    </strong>
                </article>

                <article class="card finance-summary-card">
                    <span>Collected</span>
                    <strong>
                        <?= e(
                            $currency . ' ' .
                            number_format(
                                (float) (
                                    $currencySummary[
                                        'total_paid'
                                    ] ?? 0
                                ),
                                2
                            )
                        ) ?>
                    </strong>
                </article>

                <article class="card finance-summary-card">
                    <span>Open receivables</span>
                    <strong>
                        <?= e(
                            $currencySummary[
                                'open_count'
                            ] ?? 0
                        ) ?>
                    </strong>
                </article>

                <article class="card finance-summary-card">
                    <span>Overdue</span>
                    <strong>
                        <?= e(
                            $currencySummary[
                                'overdue_count'
                            ] ?? 0
                        ) ?>
                    </strong>
                </article>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<section
    class="card table-card"
    aria-labelledby="sales-receivables-title"
>
    <div class="table-summary">
        <div>
            <strong id="sales-receivables-title">
                Sales Receivables
            </strong>
            <small class="table-summary-note">
                Customer balances created from confirmed
                Sales orders.
            </small>
        </div>

        <span>
            <?= e($receivableTotal) ?>
            <?= $receivableTotal === 1
                ? 'receivable'
                : 'receivables' ?>
        </span>
    </div>

    <form
        method="get"
        action="/office_app/public/finance"
        class="finance-filter-form"
        aria-label="Sales receivable filters"
    >
        <input
            type="hidden"
            name="search"
            value="<?= e($filters['search'] ?? '') ?>"
        >
        <input
            type="hidden"
            name="status"
            value="<?= e($filters['status'] ?? '') ?>"
        >
        <input
            type="hidden"
            name="page"
            value="<?= e($pagination['page'] ?? 1) ?>"
        >

        <div class="form-field finance-search-field">
            <label for="receivable-search">
                Search receivables
            </label>
            <input
                id="receivable-search"
                name="receivable_search"
                type="search"
                value="<?= e(
                    $receivableFilters['search'] ?? ''
                ) ?>"
                placeholder="Order, customer name or number"
                maxlength="100"
            >
        </div>

        <div class="form-field">
            <label for="receivable-status">
                Receivable status
            </label>
            <select
                id="receivable-status"
                name="receivable_status"
            >
                <option value="">All statuses</option>

                <?php foreach (
                    $receivableStatusOptions
                    as $value => $label
                ): ?>
                    <option
                        value="<?= e($value) ?>"
                        <?= (
                            $receivableFilters['status']
                            ?? ''
                        ) === $value
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-actions">
            <button
                type="submit"
                class="btn btn-primary"
            >
                Apply filters
            </button>

            <a
                href="<?= e(financeDashboardUrl(
                    $filters
                )) ?>"
                class="btn btn-secondary"
            >
                Reset
            </a>
        </div>
    </form>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Original</th>
                    <th>Paid</th>
                    <th>Outstanding</th>
                    <th>Due date</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>
            <?php if ($receivables === []): ?>
                <tr>
                    <td
                        colspan="7"
                        class="empty-state"
                    >
                        No Sales receivables have been
                        posted.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach (
                    $receivables as $receivable
                ): ?>
                    <?php
                    $currency = strtoupper((string) (
                        $receivable['currency'] ?? ''
                    ));
                    $status = (string) (
                        $receivable['status'] ?? ''
                    );
                    $isOverdue = (int) (
                        $receivable['is_overdue'] ?? 0
                    ) === 1;

                    $statusTone = $isOverdue
                        ? 'danger'
                        : (
                            $status === 'paid'
                                ? 'success'
                                : 'warning'
                        );

                    $statusLabel = $isOverdue
                        ? 'Overdue'
                        : ucwords(str_replace(
                            '_',
                            ' ',
                            $status
                        ));
                    ?>

                    <tr>
                        <td>
                            <strong>
                                <?= e(
                                    $receivable[
                                        'order_number'
                                    ] ?? ''
                                ) ?>
                            </strong>
                        </td>

                        <td>
                            <strong>
                                <?= e(
                                    $receivable[
                                        'customer_name'
                                    ] ?? 'Unknown customer'
                                ) ?>
                            </strong>

                            <small>
                                <?= e(
                                    $receivable[
                                        'customer_number'
                                    ] ?? ''
                                ) ?>
                            </small>
                        </td>

                        <td>
                            <?= e(
                                $currency . ' ' .
                                number_format(
                                    (float) (
                                        $receivable[
                                            'original_amount'
                                        ] ?? 0
                                    ),
                                    2
                                )
                            ) ?>
                        </td>

                        <td>
                            <?= e(
                                $currency . ' ' .
                                number_format(
                                    (float) (
                                        $receivable[
                                            'paid_amount'
                                        ] ?? 0
                                    ),
                                    2
                                )
                            ) ?>
                        </td>

                        <td>
                            <strong>
                                <?= e(
                                    $currency . ' ' .
                                    number_format(
                                        (float) (
                                            $receivable[
                                                'balance_amount'
                                            ] ?? 0
                                        ),
                                        2
                                    )
                                ) ?>
                            </strong>
                        </td>

                        <td>
                            <?= e($formatDate(
                                $receivable['due_date']
                                ?? null
                            )) ?>
                        </td>

                        <td>
                            <span class="badge badge-<?= e(
                                $statusTone
                            ) ?>">
                                <?= e($statusLabel) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if (
        ($receivablePagination['lastPage'] ?? 1) > 1
    ): ?>
        <?php
        $receivablePage = (int) (
            $receivablePagination['page'] ?? 1
        );
        $receivableLastPage = (int) (
            $receivablePagination['lastPage'] ?? 1
        );

        $receivableQuery = array_merge(
            $filters,
            [
                'receivable_search' =>
                    $receivableFilters['search'] ?? '',
                'receivable_status' =>
                    $receivableFilters['status'] ?? '',
            ]
        );
        ?>

        <nav
            class="pagination"
            aria-label="Sales receivable pagination"
        >
            <?php if ($receivablePage > 1): ?>
                <a
                    class="pagination-link"
                    href="<?= e(financeDashboardUrl(
                        $receivableQuery,
                        [
                            'receivable_page' =>
                                $receivablePage - 1,
                        ]
                    )) ?>"
                >
                    Previous
                </a>
            <?php endif; ?>

            <span class="pagination-status">
                Page <?= e($receivablePage) ?>
                of <?= e($receivableLastPage) ?>
            </span>

            <?php if (
                $receivablePage < $receivableLastPage
            ): ?>
                <a
                    class="pagination-link"
                    href="<?= e(financeDashboardUrl(
                        $receivableQuery,
                        [
                            'receivable_page' =>
                                $receivablePage + 1,
                        ]
                    )) ?>"
                >
                    Next
                </a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
<section
    class="card table-card"
    aria-labelledby="recent-journals-title"
>
    <div class="table-summary">
        <div>
            <strong id="recent-journals-title">
                Recent Journal Postings
            </strong>
            <small class="table-summary-note">
                Posted accounting entries generated by
                Finance integrations.
            </small>
        </div>

        <span>
            <?= e(count($recentJournals)) ?>
            <?= count($recentJournals) === 1
                ? 'journal'
                : 'journals' ?>
        </span>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Batch</th>
                    <th>Source</th>
                    <th>Description</th>
                    <th>Posting date</th>
                    <th>Debit</th>
                    <th>Credit</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>
            <?php if ($recentJournals === []): ?>
                <tr>
                    <td
                        colspan="7"
                        class="empty-state"
                    >
                        No journal postings are available.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach (
                    $recentJournals as $journal
                ): ?>
                    <?php
                    $currency = strtoupper((string) (
                        $journal['currency'] ?? ''
                    ));
                    $status = (string) (
                        $journal['status'] ?? ''
                    );
                    $isBalanced = abs(
                        (float) (
                            $journal['total_debit'] ?? 0
                        ) -
                        (float) (
                            $journal['total_credit'] ?? 0
                        )
                    ) < 0.005;
                    ?>

                    <tr>
                        <td>
                            <strong>
                                <?= e(
                                    $journal[
                                        'batch_number'
                                    ] ?? ''
                                ) ?>
                            </strong>

                            <small>
                                <?= e(
                                    $journal[
                                        'source_number'
                                    ] ?? ''
                                ) ?>
                            </small>
                        </td>

                        <td>
                            <?= e(ucwords(str_replace(
                                '_',
                                ' ',
                                (string) (
                                    $journal[
                                        'source_type'
                                    ] ?? ''
                                )
                            ))) ?>
                        </td>

                        <td>
                            <?= e(
                                $journal[
                                    'description'
                                ] ?? ''
                            ) ?>
                        </td>

                        <td>
                            <?= e($formatDate(
                                $journal[
                                    'posting_date'
                                ] ?? null
                            )) ?>
                        </td>

                        <td>
                            <strong>
                                <?= e(
                                    $currency . ' ' .
                                    number_format(
                                        (float) (
                                            $journal[
                                                'total_debit'
                                            ] ?? 0
                                        ),
                                        2
                                    )
                                ) ?>
                            </strong>
                        </td>

                        <td>
                            <strong>
                                <?= e(
                                    $currency . ' ' .
                                    number_format(
                                        (float) (
                                            $journal[
                                                'total_credit'
                                            ] ?? 0
                                        ),
                                        2
                                    )
                                ) ?>
                            </strong>
                        </td>

                        <td>
                            <span class="badge badge-<?= e(
                                $status === 'posted'
                                && $isBalanced
                                    ? 'success'
                                    : 'warning'
                            ) ?>">
                                <?= e(
                                    $status === 'posted'
                                    && $isBalanced
                                        ? 'Posted - Balanced'
                                        : ucwords($status)
                                ) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<section
    class="card table-card"
    aria-labelledby="recent-receipts-title"
>
    <div class="table-summary">
        <div>
            <strong id="recent-receipts-title">
                Recent Sales Receipts
            </strong>
            <small class="table-summary-note">
                Customer payments posted against
                Sales receivables.
            </small>
        </div>

        <span>
            <?= e(count($recentReceipts)) ?>
            <?= count($recentReceipts) === 1
                ? 'receipt'
                : 'receipts' ?>
        </span>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Receipt</th>
                    <th>Order</th>
                    <th>Payment date</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th>Amount</th>
                </tr>
            </thead>

            <tbody>
            <?php if ($recentReceipts === []): ?>
                <tr>
                    <td
                        colspan="6"
                        class="empty-state"
                    >
                        No Sales receipts have been
                        posted yet.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach (
                    $recentReceipts as $receipt
                ): ?>
                    <?php
                    $currency = strtoupper((string) (
                        $receipt['currency'] ?? ''
                    ));
                    ?>

                    <tr>
                        <td>
                            <strong>
                                <?= e(
                                    $receipt[
                                        'receipt_number'
                                    ] ?? ''
                                ) ?>
                            </strong>
                        </td>

                        <td>
                            <?= e(
                                $receipt[
                                    'order_number'
                                ] ?? ''
                            ) ?>
                        </td>

                        <td>
                            <?= e($formatDate(
                                $receipt[
                                    'payment_date'
                                ] ?? null
                            )) ?>
                        </td>

                        <td>
                            <?= e(ucwords(str_replace(
                                '_',
                                ' ',
                                (string) (
                                    $receipt[
                                        'payment_method'
                                    ] ?? ''
                                )
                            ))) ?>
                        </td>

                        <td>
                            <?= e(
                                $receipt[
                                    'reference_number'
                                ] ?? 'Not recorded'
                            ) ?>
                        </td>

                        <td>
                            <strong>
                                <?= e(
                                    $currency . ' ' .
                                    number_format(
                                        (float) (
                                            $receipt[
                                                'amount'
                                            ] ?? 0
                                        ),
                                        2
                                    )
                                ) ?>
                            </strong>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<section
    class="section-heading"
    aria-labelledby="expense-requests-title"
>
    <div>
        <h2 id="expense-requests-title">
            Expense Requests
        </h2>
        <p>
            Employee expense requests, approvals
            and payment workflow.
        </p>
    </div>
</section>
<section
    class="finance-summary-grid"
    aria-label="Expense request summary"
>
    <article class="card finance-summary-card">
        <span>Total requests</span>
        <strong>
            <?= e($summary['total'] ?? 0) ?>
        </strong>
    </article>
    <article class="card finance-summary-card">
        <span>Awaiting review</span>
        <strong>
            <?= e($summary['submitted'] ?? 0) ?>
        </strong>
    </article>
    <article class="card finance-summary-card">
        <span>Approved</span>
        <strong>
            <?= e($summary['approved'] ?? 0) ?>
        </strong>
    </article>
    <article class="card finance-summary-card">
        <span>Paid</span>
        <strong>
            <?= e($summary['paid'] ?? 0) ?>
        </strong>
    </article>
</section>

<section class="card finance-filter-panel">
    <form
        method="get"
        action="/office_app/public/finance"
        class="finance-filter-form"
    >
        <div class="form-field finance-search-field">
            <label for="finance-search">
                Search expense requests
            </label>
            <input
                id="finance-search"
                name="search"
                type="search"
                value="<?= e(
                    $filters['search'] ?? ''
                ) ?>"
                placeholder="Request number, title, requester or category"
                maxlength="100"
            >
        </div>

        <div class="form-field">
            <label for="finance-status">
                Request status
            </label>
            <select
                id="finance-status"
                name="status"
            >
                <option value="">All statuses</option>
                <?php foreach (
                    $statusOptions as $value => $label
                ): ?>
                    <option
                        value="<?= e($value) ?>"
                        <?= (
                            $filters['status'] ?? ''
                        ) === $value
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-actions">
            <button
                type="submit"
                class="btn btn-primary"
            >
                Apply filters
            </button>
            <a
                href="/office_app/public/finance"
                class="btn btn-secondary"
            >
                Reset
            </a>
        </div>
    </form>
</section>

<section class="card table-card">
    <div class="table-summary">
        <div>
            <strong>
                <?= e($totalRequests) ?>
                <?= $totalRequests === 1
                    ? 'expense request'
                    : 'expense requests' ?>
            </strong>
            <small class="table-summary-note">
                Financial totals are shown per request
                currency.
            </small>
        </div>
        <span>
            Showing
            <?= e($pagination['from'] ?? 0) ?>
            &ndash;
            <?= e($pagination['to'] ?? 0) ?>
        </span>
    </div>

    <div class="table-responsive">
        <table class="data-table finance-request-table">
            <thead>
                <tr>
                    <th>Request</th>
                    <th>Requester</th>
                    <th>Category</th>
                    <th>Expense date</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($requests === []): ?>
                <tr>
                    <td
                        colspan="7"
                        class="empty-state"
                    >
                        No expense requests matched the
                        selected filters.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach (
                    $requests as $request
                ): ?>
                    <tr>
                        <td>
                            <strong>
                                <?= e(
                                    $request[
                                        'request_number'
                                    ] ?? ''
                                ) ?>
                            </strong>
                            <small>
                                <?= e(
                                    $request['title'] ?? ''
                                ) ?>
                            </small>
                        </td>
                        <td>
                            <strong>
                                <?= e(
                                    $request[
                                        'requesterName'
                                    ] ?? ''
                                ) ?>
                            </strong>
                            <small>
                                <?= e(
                                    $request[
                                        'employee_number'
                                    ] ?? ''
                                ) ?>
                            </small>
                        </td>
                        <td>
                            <?= e(
                                $request['category_name']
                                ?? 'Uncategorized'
                            ) ?>
                        </td>
                        <td>
                            <?= e($formatDate(
                                $request['expense_date']
                                ?? null
                            )) ?>
                        </td>
                        <td>
                            <strong>
                                <?= e(
                                    $request['amountLabel']
                                    ?? ''
                                ) ?>
                            </strong>
                        </td>
                        <td>
                            <span class="badge badge-<?= e(
                                $request['statusTone']
                                ?? 'muted'
                            ) ?>">
                                <?= e(
                                    $request['statusLabel']
                                    ?? ''
                                ) ?>
                            </span>
                        </td>
                        <td>
                            <?= e($formatDate(
                                $request['submitted_at']
                                ?? null
                            )) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if (
        ($pagination['lastPage'] ?? 1) > 1
    ): ?>
        <?php
        $page = (int) ($pagination['page'] ?? 1);
        $lastPage = (int) (
            $pagination['lastPage'] ?? 1
        );
        ?>

        <nav
            class="pagination"
            aria-label="Expense request pagination"
        >
            <?php if ($page > 1): ?>
                <a
                    class="pagination-link"
                    href="<?= e(financeDashboardUrl(
                        $filters,
                        ['page' => $page - 1]
                    )) ?>"
                >
                    Previous
                </a>
            <?php endif; ?>

            <span class="pagination-status">
                Page <?= e($page) ?>
                of <?= e($lastPage) ?>
            </span>

            <?php if ($page < $lastPage): ?>
                <a
                    class="pagination-link"
                    href="<?= e(financeDashboardUrl(
                        $filters,
                        ['page' => $page + 1]
                    )) ?>"
                >
                    Next
                </a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
