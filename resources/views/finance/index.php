<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
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
