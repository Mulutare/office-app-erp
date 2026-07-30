<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$workspace = is_array($data['workspace'] ?? null)
    ? $data['workspace']
    : [];
$employees = is_array($workspace['employees'] ?? null)
    ? $workspace['employees']
    : [];
$employee = is_array($workspace['employee'] ?? null)
    ? $workspace['employee']
    : null;
$balances = is_array($workspace['balances'] ?? null)
    ? $workspace['balances']
    : [];
$summary = is_array($workspace['summary'] ?? null)
    ? $workspace['summary']
    : [];
$years = is_array($workspace['years'] ?? null)
    ? $workspace['years']
    : [];
$year = (int) ($workspace['year'] ?? date('Y'));
$employeeId = (int) (
    $workspace['employeeId'] ?? 0
);
$selectedPolicy = is_array(
    $workspace['selectedPolicy'] ?? null
)
    ? $workspace['selectedPolicy']
    : null;
$allocation = is_array(
    $workspace['allocation'] ?? null
)
    ? $workspace['allocation']
    : null;
$allocationForm = is_array(
    $workspace['allocationForm'] ?? null
)
    ? $workspace['allocationForm']
    : [];
$adjustments = is_array(
    $workspace['adjustments'] ?? null
)
    ? $workspace['adjustments']
    : [];
$notice = is_array($data['notice'] ?? null)
    ? $data['notice']
    : null;
$allocationErrors = is_array(
    $data['allocationErrors'] ?? null
)
    ? $data['allocationErrors']
    : [];
$allocationOld = is_array(
    $data['allocationOld'] ?? null
)
    ? $data['allocationOld']
    : [];
$adjustmentErrors = is_array(
    $data['adjustmentErrors'] ?? null
)
    ? $data['adjustmentErrors']
    : [];
$adjustmentOld = is_array(
    $data['adjustmentOld'] ?? null
)
    ? $data['adjustmentOld']
    : [];
$policyId = (int) (
    $selectedPolicy['leave_type_id'] ?? 0
);
$employeeName = (string) (
    $employee['displayName'] ?? 'No employee selected'
);
$allocationValue = static function (
    string $key,
    mixed $default = ''
) use ($allocationOld, $allocationForm): mixed {
    return array_key_exists($key, $allocationOld)
        ? $allocationOld[$key]
        : ($allocationForm[$key] ?? $default);
};
$adjustmentValue = static function (
    string $key,
    mixed $default = ''
) use ($adjustmentOld): mixed {
    return array_key_exists($key, $adjustmentOld)
        ? $adjustmentOld[$key]
        : $default;
};
$formatDate = static function (
    mixed $value,
    bool $includeTime = false
): string {
    if (!is_string($value) || trim($value) === '') {
        return 'Not recorded';
    }

    $timestamp = strtotime($value);

    return $timestamp === false
        ? $value
        : date(
            $includeTime
                ? 'd M Y, H:i'
                : 'd M Y',
            $timestamp
        );
};
$defaultEffectiveDate = $year === (int) date('Y')
    ? date('Y-m-d')
    : sprintf('%04d-01-01', $year);
?>

<nav class="workspace-breadcrumb" aria-label="Breadcrumb">
    <a href="/office_app/public/hr">HR</a>
    <span aria-hidden="true">/</span>
    <a href="/office_app/public/hr/leave">Leave</a>
    <span aria-hidden="true">/</span>
    <strong>Balances</strong>
    <span class="breadcrumb-actions">
        <a
            href="/office_app/public/hr/leave/policies"
            class="breadcrumb-action"
        >
            Configure policies
        </a>
    </span>
</nav>

<?php if ($notice !== null): ?>
    <div
        class="alert alert-<?= e(
            $notice['type'] ?? 'success'
        ) ?>"
        role="status"
    >
        <?= e($notice['message'] ?? '') ?>
    </div>
<?php endif; ?>

<section class="balance-hero">
    <div>
        <span class="section-kicker">
            Annual leave control
        </span>
        <h2>Allocate, reconcile and audit leave days.</h2>
        <p>
            Set employee-specific entitlement and carry-over,
            then record every correction as a permanent credit
            or debit. Approved leave is deducted automatically.
        </p>
    </div>
    <div class="balance-hero-context">
        <span>Active record</span>
        <strong><?= e($employeeName) ?></strong>
        <small><?= e($year) ?> balance year</small>
    </div>
</section>

<section class="card balance-filter-card">
    <form
        method="get"
        action="/office_app/public/hr/leave/balances"
        class="balance-filter-form"
    >
        <div class="form-field">
            <label for="balance-employee">Employee</label>
            <select
                id="balance-employee"
                name="employee"
                required
            >
                <?php if ($employees === []): ?>
                    <option value="">
                        No active employees
                    </option>
                <?php else: ?>
                    <?php foreach ($employees as $option): ?>
                        <?php
                        $optionId = (int) (
                            $option['employee_id'] ?? 0
                        );
                        ?>
                        <option
                            value="<?= e($optionId) ?>"
                            <?= $optionId === $employeeId
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e(
                                $option['displayName'] ?? ''
                            ) ?>
                            ·
                            <?= e(
                                $option['employee_number']
                                    ?? ''
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>

        <div class="form-field">
            <label for="balance-year">
                Balance year
            </label>
            <select id="balance-year" name="year">
                <?php foreach ($years as $yearOption): ?>
                    <option
                        value="<?= e($yearOption) ?>"
                        <?= (int) $yearOption === $year
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e($yearOption) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button
            type="submit"
            class="btn btn-primary"
            <?= $employees === [] ? 'disabled' : '' ?>
        >
            Load balances
        </button>
    </form>
</section>

<?php if ($employee === null): ?>
    <section class="card balance-empty-state">
        <span class="section-kicker">
            Employee setup required
        </span>
        <h2>No active employee records are available.</h2>
        <p>
            Create or activate an employee before allocating
            leave balances.
        </p>
        <a
            href="/office_app/public/hr"
            class="btn btn-secondary"
        >
            Open employee directory
        </a>
    </section>
<?php else: ?>
    <section class="operations-summary-grid">
        <article class="operations-summary-card is-primary">
            <span>Available</span>
            <strong>
                <?= e($summary['allocated'] ?? '0.00') ?>
            </strong>
            <small>Entitlement, carry-over and adjustments</small>
        </article>
        <article class="operations-summary-card">
            <span>Approved usage</span>
            <strong>
                <?= e($summary['used'] ?? '0.00') ?>
            </strong>
            <small>Days deducted in <?= e($year) ?></small>
        </article>
        <article class="operations-summary-card">
            <span>Remaining</span>
            <strong>
                <?= e($summary['remaining'] ?? '0.00') ?>
            </strong>
            <small>Current calculated balance</small>
        </article>
        <article class="operations-summary-card">
            <span>Policies</span>
            <strong>
                <?= e($summary['policies'] ?? 0) ?>
            </strong>
            <small>Active leave categories</small>
        </article>
    </section>

    <section class="card table-card balance-table-card">
        <div class="table-summary">
            <div>
                <strong><?= e($employeeName) ?></strong>
                <span>
                    <?= e(
                        $employee['employee_number'] ?? ''
                    ) ?>
                    · <?= e($year) ?> annual position
                </span>
            </div>
            <span>
                <?= e(count($balances)) ?> active policies
            </span>
        </div>

        <div class="table-responsive">
            <table class="data-table balance-table">
                <thead>
                    <tr>
                        <th>Policy</th>
                        <th>Entitlement</th>
                        <th>Carry-over</th>
                        <th>Adjustments</th>
                        <th>Available</th>
                        <th>Used</th>
                        <th>Remaining</th>
                        <th class="table-actions-column">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($balances === []): ?>
                    <tr>
                        <td colspan="8" class="empty-state">
                            No active leave policies are configured.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($balances as $balance): ?>
                        <?php
                        $rowPolicyId = (int) (
                            $balance['leave_type_id'] ?? 0
                        );
                        $remaining = (float) (
                            $balance['remainingDays'] ?? 0
                        );
                        $adjustmentDays = (float) (
                            $balance['adjustmentDays'] ?? 0
                        );
                        ?>
                        <tr class="<?= $rowPolicyId === $policyId
                            ? 'is-selected'
                            : '' ?>">
                            <td>
                                <strong>
                                    <?= e(
                                        $balance['name'] ?? ''
                                    ) ?>
                                </strong>
                                <small class="policy-code">
                                    <?= e(
                                        $balance['code'] ?? ''
                                    ) ?>
                                </small>
                            </td>
                            <td>
                                <?= e(
                                    $balance[
                                        'entitlementDays'
                                    ] ?? '0.00'
                                ) ?>
                            </td>
                            <td>
                                <?= e(
                                    $balance[
                                        'carryOverDays'
                                    ] ?? '0.00'
                                ) ?>
                            </td>
                            <td>
                                <span class="<?= $adjustmentDays < 0
                                    ? 'balance-negative'
                                    : 'balance-positive' ?>">
                                    <?= e(
                                        $balance[
                                            'adjustmentDays'
                                        ] ?? '0.00'
                                    ) ?>
                                </span>
                            </td>
                            <td>
                                <strong>
                                    <?= e(
                                        $balance[
                                            'availableDays'
                                        ] ?? '0.00'
                                    ) ?>
                                </strong>
                            </td>
                            <td>
                                <?= e(
                                    $balance['usedDays']
                                        ?? '0.00'
                                ) ?>
                            </td>
                            <td>
                                <strong class="<?= $remaining < 0
                                    ? 'balance-negative'
                                    : '' ?>">
                                    <?= e(
                                        $balance[
                                            'remainingDays'
                                        ] ?? '0.00'
                                    ) ?>
                                </strong>
                            </td>
                            <td>
                                <a
                                    class="table-link"
                                    href="/office_app/public/hr/leave/balances?<?= e(
                                        http_build_query([
                                            'employee' =>
                                                $employeeId,
                                            'year' => $year,
                                            'policy' =>
                                                $rowPolicyId,
                                        ])
                                    ) ?>"
                                >
                                    Configure
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($selectedPolicy !== null): ?>
        <section class="balance-editor-grid">
            <article class="card balance-editor-card">
                <span class="section-kicker">
                    Annual allocation
                </span>
                <h2>
                    <?= e($selectedPolicy['name'] ?? '') ?>
                </h2>
                <p class="form-help">
                    Override the policy entitlement for this
                    employee and record approved carry-over.
                </p>

                <?php if (
                    !empty($allocationErrors['form'])
                ): ?>
                    <div class="alert alert-danger" role="alert">
                        <?= e(
                            $allocationErrors['form']
                        ) ?>
                    </div>
                <?php endif; ?>

                <form
                    method="post"
                    action="/office_app/public/hr/leave/balances/allocation"
                    class="operations-form"
                >
                    <?= csrfField() ?>
                    <input
                        type="hidden"
                        name="employee_id"
                        value="<?= e($employeeId) ?>"
                    >
                    <input
                        type="hidden"
                        name="leave_type_id"
                        value="<?= e($policyId) ?>"
                    >
                    <input
                        type="hidden"
                        name="year"
                        value="<?= e($year) ?>"
                    >

                    <div class="balance-form-row">
                        <div class="form-field">
                            <label for="entitlement-days">
                                Entitlement days
                            </label>
                            <input
                                id="entitlement-days"
                                name="entitlement_days"
                                type="number"
                                min="0"
                                max="366"
                                step="0.01"
                                value="<?= e(
                                    $allocationValue(
                                        'entitlement_days',
                                        '0.00'
                                    )
                                ) ?>"
                                required
                            >
                            <?php if (!empty(
                                $allocationErrors[
                                    'entitlement_days'
                                ]
                            )): ?>
                                <small class="field-error">
                                    <?= e(
                                        $allocationErrors[
                                            'entitlement_days'
                                        ]
                                    ) ?>
                                </small>
                            <?php endif; ?>
                        </div>

                        <div class="form-field">
                            <label for="carry-over-days">
                                Carry-over days
                            </label>
                            <input
                                id="carry-over-days"
                                name="carry_over_days"
                                type="number"
                                min="0"
                                max="366"
                                step="0.01"
                                value="<?= e(
                                    $allocationValue(
                                        'carry_over_days',
                                        '0.00'
                                    )
                                ) ?>"
                                required
                            >
                            <?php if (!empty(
                                $allocationErrors[
                                    'carry_over_days'
                                ]
                            )): ?>
                                <small class="field-error">
                                    <?= e(
                                        $allocationErrors[
                                            'carry_over_days'
                                        ]
                                    ) ?>
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-field">
                        <label for="allocation-notes">
                            Allocation notes
                        </label>
                        <textarea
                            id="allocation-notes"
                            name="notes"
                            rows="4"
                            maxlength="500"
                            placeholder="Optional policy exception or carry-over approval reference"
                        ><?= e(
                            $allocationValue('notes', '')
                        ) ?></textarea>
                        <?php if (!empty(
                            $allocationErrors['notes']
                        )): ?>
                            <small class="field-error">
                                <?= e(
                                    $allocationErrors['notes']
                                ) ?>
                            </small>
                        <?php endif; ?>
                    </div>

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        Save annual allocation
                    </button>
                </form>

                <?php if ($allocation !== null): ?>
                    <p class="balance-record-meta">
                        Last updated
                        <?= e($formatDate(
                            $allocation['updated_at']
                                ?? null,
                            true
                        )) ?>.
                    </p>
                <?php endif; ?>
            </article>

            <article class="card balance-editor-card">
                <span class="section-kicker">
                    Balance adjustment
                </span>
                <h2>Record a controlled correction.</h2>
                <p class="form-help">
                    Credits add days; debits subtract days.
                    Adjustments cannot be edited after posting.
                </p>

                <?php if (
                    !empty($adjustmentErrors['form'])
                ): ?>
                    <div class="alert alert-danger" role="alert">
                        <?= e(
                            $adjustmentErrors['form']
                        ) ?>
                    </div>
                <?php endif; ?>

                <form
                    method="post"
                    action="/office_app/public/hr/leave/balances/adjustment"
                    class="operations-form"
                >
                    <?= csrfField() ?>
                    <input
                        type="hidden"
                        name="employee_id"
                        value="<?= e($employeeId) ?>"
                    >
                    <input
                        type="hidden"
                        name="leave_type_id"
                        value="<?= e($policyId) ?>"
                    >
                    <input
                        type="hidden"
                        name="year"
                        value="<?= e($year) ?>"
                    >

                    <div class="balance-form-row">
                        <div class="form-field">
                            <label for="adjustment-type">
                                Adjustment type
                            </label>
                            <?php
                            $selectedType = (string) (
                                $adjustmentValue(
                                    'adjustment_type',
                                    'credit'
                                )
                            );
                            ?>
                            <select
                                id="adjustment-type"
                                name="adjustment_type"
                                required
                            >
                                <option
                                    value="credit"
                                    <?= $selectedType === 'credit'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Credit — add days
                                </option>
                                <option
                                    value="debit"
                                    <?= $selectedType === 'debit'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Debit — subtract days
                                </option>
                            </select>
                            <?php if (!empty(
                                $adjustmentErrors[
                                    'adjustment_type'
                                ]
                            )): ?>
                                <small class="field-error">
                                    <?= e(
                                        $adjustmentErrors[
                                            'adjustment_type'
                                        ]
                                    ) ?>
                                </small>
                            <?php endif; ?>
                        </div>

                        <div class="form-field">
                            <label for="adjustment-days">
                                Days
                            </label>
                            <input
                                id="adjustment-days"
                                name="days"
                                type="number"
                                min="0.01"
                                max="366"
                                step="0.01"
                                value="<?= e(
                                    $adjustmentValue('days', '')
                                ) ?>"
                                required
                            >
                            <?php if (!empty(
                                $adjustmentErrors['days']
                            )): ?>
                                <small class="field-error">
                                    <?= e(
                                        $adjustmentErrors['days']
                                    ) ?>
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-field">
                        <label for="effective-date">
                            Effective date
                        </label>
                        <input
                            id="effective-date"
                            name="effective_date"
                            type="date"
                            min="<?= e($year) ?>-01-01"
                            max="<?= e($year) ?>-12-31"
                            value="<?= e(
                                $adjustmentValue(
                                    'effective_date',
                                    $defaultEffectiveDate
                                )
                            ) ?>"
                            required
                        >
                        <?php if (!empty(
                            $adjustmentErrors[
                                'effective_date'
                            ]
                        )): ?>
                            <small class="field-error">
                                <?= e(
                                    $adjustmentErrors[
                                        'effective_date'
                                    ]
                                ) ?>
                            </small>
                        <?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label for="adjustment-reason">
                            Reason and reference
                        </label>
                        <textarea
                            id="adjustment-reason"
                            name="reason"
                            rows="4"
                            minlength="3"
                            maxlength="500"
                            placeholder="Explain why this correction is required"
                            required
                        ><?= e(
                            $adjustmentValue('reason', '')
                        ) ?></textarea>
                        <?php if (!empty(
                            $adjustmentErrors['reason']
                        )): ?>
                            <small class="field-error">
                                <?= e(
                                    $adjustmentErrors['reason']
                                ) ?>
                            </small>
                        <?php endif; ?>
                    </div>

                    <button
                        type="submit"
                        class="btn btn-secondary"
                    >
                        Post adjustment
                    </button>
                </form>
            </article>
        </section>
    <?php else: ?>
        <section class="card balance-selection-guide">
            <span class="section-kicker">
                Allocation controls
            </span>
            <h2>Select Configure beside a leave policy.</h2>
            <p>
                The selected policy opens allocation and
                adjustment controls for <?= e($employeeName) ?>.
            </p>
        </section>
    <?php endif; ?>

    <section class="card table-card balance-ledger-card">
        <div class="table-summary">
            <div>
                <strong>Adjustment ledger</strong>
                <span>
                    Permanent balance corrections for
                    <?= e($employeeName) ?> in <?= e($year) ?>.
                </span>
            </div>
            <span><?= e(count($adjustments)) ?> entries</span>
        </div>

        <div class="table-responsive">
            <table class="data-table balance-ledger-table">
                <thead>
                    <tr>
                        <th>Effective</th>
                        <th>Policy</th>
                        <th>Type</th>
                        <th>Days</th>
                        <th>Reason</th>
                        <th>Recorded by</th>
                        <th>Recorded</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($adjustments === []): ?>
                    <tr>
                        <td colspan="7" class="empty-state">
                            No balance adjustments have been
                            recorded for this employee and year.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($adjustments as $entry): ?>
                        <?php
                        $isDebit = (
                            $entry['adjustment_type'] ?? ''
                        ) === 'debit';
                        ?>
                        <tr>
                            <td>
                                <?= e($formatDate(
                                    $entry['effective_date']
                                        ?? null
                                )) ?>
                            </td>
                            <td>
                                <strong>
                                    <?= e(
                                        $entry[
                                            'leave_type_name'
                                        ] ?? ''
                                    ) ?>
                                </strong>
                                <small class="policy-code">
                                    <?= e(
                                        $entry[
                                            'leave_type_code'
                                        ] ?? ''
                                    ) ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge <?= $isDebit
                                    ? 'badge-danger'
                                    : 'badge-success' ?>">
                                    <?= e(
                                        $entry['typeLabel']
                                            ?? ''
                                    ) ?>
                                </span>
                            </td>
                            <td>
                                <strong class="<?= $isDebit
                                    ? 'balance-negative'
                                    : 'balance-positive' ?>">
                                    <?= e(
                                        $entry[
                                            'adjustmentDays'
                                        ] ?? '0.00'
                                    ) ?>
                                </strong>
                            </td>
                            <td class="balance-ledger-reason">
                                <?= e($entry['reason'] ?? '') ?>
                            </td>
                            <td>
                                <?= e(
                                    $entry['created_by_name']
                                        ?? 'System'
                                ) ?>
                            </td>
                            <td>
                                <?= e($formatDate(
                                    $entry['created_at']
                                        ?? null,
                                    true
                                )) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
