<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$employees = is_array(
    $data['employees'] ?? null
)
    ? $data['employees']
    : [];
$departments = is_array(
    $data['departments'] ?? null
)
    ? $data['departments']
    : [];
$statusOptions = is_array(
    $data['statusOptions'] ?? null
)
    ? $data['statusOptions']
    : [];
$summary = is_array($data['summary'] ?? null)
    ? $data['summary']
    : [];
$filters = is_array($data['filters'] ?? null)
    ? $data['filters']
    : [];
$pagination = is_array(
    $data['pagination'] ?? null
)
    ? $data['pagination']
    : [];

$formatDate = static function (mixed $value): string {
    if (!is_string($value) || trim($value) === '') {
        return 'Not recorded';
    }

    $timestamp = strtotime($value);

    return $timestamp === false
        ? $value
        : date('M j, Y', $timestamp);
};

function employeeDirectoryUrl(
    array $filters,
    array $overrides = []
): string {
    $query = array_merge(
        $filters,
        $overrides
    );

    foreach ($query as $key => $value) {
        if (
            $value === ''
            || $value === null
            || $value === 0
        ) {
            unset($query[$key]);
        }
    }

    return '/office_app/public/hr'
        . ($query === []
            ? ''
            : '?' . http_build_query($query));
}

$totalEmployees = array_sum(array_map(
    'intval',
    $summary
));
?>

<section class="hr-summary-grid">
    <article class="card hr-summary-card">
        <span>Total employees</span>
        <strong><?= e($totalEmployees) ?></strong>
    </article>
    <article class="card hr-summary-card">
        <span>Active</span>
        <strong>
            <?= e($summary['active'] ?? 0) ?>
        </strong>
    </article>
    <article class="card hr-summary-card">
        <span>On leave</span>
        <strong>
            <?= e($summary['on_leave'] ?? 0) ?>
        </strong>
    </article>
    <article class="card hr-summary-card">
        <span>Terminated</span>
        <strong>
            <?= e($summary['terminated'] ?? 0) ?>
        </strong>
    </article>
</section>

<section class="card hr-filter-panel">
    <form
        method="get"
        action="/office_app/public/hr"
        class="hr-filter-form"
    >
        <div class="form-field hr-search-field">
            <label for="employee-search">
                Search employees
            </label>
            <input
                id="employee-search"
                name="search"
                type="search"
                value="<?= e(
                    $filters['search'] ?? ''
                ) ?>"
                placeholder="Name, employee number, email or job title"
                maxlength="100"
            >
        </div>

        <div class="form-field">
            <label for="employee-status">
                Employment status
            </label>
            <select
                id="employee-status"
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

        <div class="form-field">
            <label for="employee-department">
                Department
            </label>
            <select
                id="employee-department"
                name="department"
            >
                <option value="0">
                    All departments
                </option>
                <?php foreach (
                    $departments as $department
                ): ?>
                    <?php
                    $departmentId = (int) (
                        $department['department_id']
                        ?? 0
                    );
                    ?>
                    <option
                        value="<?= e($departmentId) ?>"
                        <?= (int) (
                            $filters['department'] ?? 0
                        ) === $departmentId
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e(
                            $department['name'] ?? ''
                        ) ?>
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
                href="/office_app/public/hr"
                class="btn btn-secondary"
            >
                Reset
            </a>
        </div>
    </form>
</section>

<section class="card table-card">
    <div class="table-summary">
        <strong>
            <?= e($pagination['total'] ?? 0) ?>
            employees
        </strong>
        <span>
            Showing
            <?= e($pagination['from'] ?? 0) ?>
            &ndash;
            <?= e($pagination['to'] ?? 0) ?>
        </span>
    </div>

    <div class="table-responsive">
        <table class="data-table employee-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Job title</th>
                    <th>Department</th>
                    <th>Employment</th>
                    <th>Contact</th>
                    <th>Status</th>
                    <th class="table-actions-column">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody>
            <?php if ($employees === []): ?>
                <tr>
                    <td
                        colspan="7"
                        class="empty-state"
                    >
                        No employees matched the filters.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach (
                    $employees as $employee
                ): ?>
                    <tr>
                        <td>
                            <strong>
                                <?= e(
                                    $employee['displayName']
                                    ?? ''
                                ) ?>
                            </strong>
                            <small>
                                <?= e(
                                    $employee[
                                        'employee_number'
                                    ] ?? ''
                                ) ?>
                            </small>
                        </td>
                        <td>
                            <?= e(
                                $employee['job_title']
                                ?? ''
                            ) ?>
                        </td>
                        <td>
                            <?= e(
                                $employee['department_name']
                                ?? 'Unassigned'
                            ) ?>
                        </td>
                        <td>
                            <strong>
                                <?= e(
                                    $employee['typeLabel']
                                    ?? ''
                                ) ?>
                            </strong>
                            <small>
                                Since
                                <?= e($formatDate(
                                    $employee['hire_date']
                                    ?? null
                                )) ?>
                            </small>
                        </td>
                        <td>
                            <?= e(
                                $employee['work_email']
                                ?? ''
                            ) ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= e(
                                $employee['statusTone']
                                ?? 'muted'
                            ) ?>">
                                <?= e(
                                    $employee['statusLabel']
                                    ?? ''
                                ) ?>
                            </span>
                        </td>
                        <td>
                            <a
                                href="/office_app/public/hr/employees/view?id=<?= e(
                                    $employee['employee_id']
                                    ?? ''
                                ) ?>"
                                class="table-link"
                            >
                                View
                            </a>
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
            aria-label="Employee pagination"
        >
            <?php if ($page > 1): ?>
                <a
                    class="pagination-link"
                    href="<?= e(employeeDirectoryUrl(
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
                    href="<?= e(employeeDirectoryUrl(
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
