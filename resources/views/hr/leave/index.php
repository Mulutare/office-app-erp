<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$requests = is_array($data['requests'] ?? null)
    ? $data['requests']
    : [];
$leaveTypes = is_array($data['leaveTypes'] ?? null)
    ? $data['leaveTypes']
    : [];
$employees = is_array($data['employees'] ?? null)
    ? $data['employees']
    : [];
$summary = is_array($data['summary'] ?? null)
    ? $data['summary']
    : [];
$statuses = is_array($data['statuses'] ?? null)
    ? $data['statuses']
    : [];
$filterStatus = (string) (
    $data['filterStatus'] ?? ''
);
$canManage = !empty($data['canManage']);
$canRequestSelf = !empty(
    $data['canRequestSelf']
);
$canApprove = !empty($data['canApprove']);
$profileRequired = !empty(
    $data['profileRequired']
);
$employee = is_array($data['employee'] ?? null)
    ? $data['employee']
    : null;
$scopeLabel = (string) (
    $data['scopeLabel'] ?? 'My leave'
);
$notice = is_array($data['notice'] ?? null)
    ? $data['notice']
    : null;
$errors = is_array($data['errors'] ?? null)
    ? $data['errors']
    : [];
$decisionErrors = is_array(
    $data['decisionErrors'] ?? null
)
    ? $data['decisionErrors']
    : [];
$old = is_array($data['old'] ?? null)
    ? $data['old']
    : [];
$selectedEmployeeId = (int) (
    $old['employee_id'] ?? 0
);
$selectedLeaveTypeId = (int) (
    $old['leave_type_id'] ?? 0
);
$formatDate = static function (mixed $value): string {
    $value = substr((string) $value, 0, 10);
    $timestamp = strtotime($value);

    return $timestamp === false
        ? $value
        : date('d M Y', $timestamp);
};
?>

<nav class="workspace-breadcrumb" aria-label="Breadcrumb">
    <a href="/office_app/public/hr">HR</a>
    <span aria-hidden="true">/</span>
    <strong>Leave management</strong>
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

<?php if (!empty($errors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['form']) ?>
    </div>
<?php endif; ?>

<?php if (!empty($decisionErrors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($decisionErrors['form']) ?>
    </div>
<?php endif; ?>

<?php if ($profileRequired): ?>
    <div class="alert alert-warning" role="status">
        <strong>Employee profile required.</strong>
        Your account belongs to this company, but it is not
        linked to an active HR employee record. Ask your HR
        administrator to link the account before requesting
        leave.
    </div>
<?php endif; ?>

<section class="operations-summary-grid">
    <article class="operations-summary-card is-primary">
        <span>Pending approval</span>
        <strong><?= e($summary['pending'] ?? 0) ?></strong>
        <small>Awaiting a decision</small>
    </article>
    <article class="operations-summary-card">
        <span>Approved</span>
        <strong><?= e($summary['approved'] ?? 0) ?></strong>
        <small>All approved requests</small>
    </article>
    <article class="operations-summary-card">
        <span>On leave today</span>
        <strong>
            <?= e($summary['onLeaveToday'] ?? 0) ?>
        </strong>
        <small>Current workforce absence</small>
    </article>
    <article class="operations-summary-card">
        <span>Upcoming</span>
        <strong><?= e($summary['upcoming'] ?? 0) ?></strong>
        <small>Approved future leave</small>
    </article>
</section>

<section class="operations-layout leave-operations-layout">
    <?php if ($canManage || $canRequestSelf): ?>
        <aside class="card operations-entry-card">
            <span class="section-kicker">New request</span>
            <h2 class="card-title">
                <?= $canManage
                    ? 'Submit employee leave'
                    : 'Request my leave' ?>
            </h2>
            <p class="form-help">
                <?= $canManage
                    ? 'Record a leave period for an employee.'
                    : 'Send your leave request to your reporting manager.' ?>
                Overlapping requests are blocked.
            </p>

            <form
                method="post"
                action="/office_app/public/hr/leave"
                class="operations-form"
            >
                <?= csrfField() ?>

                <?php if ($canManage): ?>
                <div class="form-field">
                    <label for="leave-employee">
                        Employee
                    </label>
                    <select
                        id="leave-employee"
                        name="employee_id"
                        required
                    >
                        <option value="">Select employee</option>
                        <?php foreach (
                            $employees as $employee
                        ): ?>
                            <?php
                            $employeeId = (int) (
                                $employee['employee_id'] ?? 0
                            );
                            ?>
                            <option
                                value="<?= e($employeeId) ?>"
                                <?= $selectedEmployeeId
                                    === $employeeId
                                        ? 'selected'
                                        : '' ?>
                            >
                                <?= e(
                                    $employee['displayName'] ?? ''
                                ) ?>
                                ·
                                <?= e(
                                    $employee['employee_number']
                                    ?? ''
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (
                        !empty($errors['employee_id'])
                    ): ?>
                        <small class="field-error">
                            <?= e($errors['employee_id']) ?>
                        </small>
                    <?php endif; ?>
                </div>
                <?php elseif ($employee !== null): ?>
                    <div class="leave-self-identity">
                        <span>Requesting for</span>
                        <strong>
                            <?= e(
                                $employee['displayName']
                                ?? ''
                            ) ?>
                        </strong>
                        <small>
                            <?= e(
                                $employee['employee_number']
                                ?? ''
                            ) ?>
                            ·
                            <?= e(
                                $employee['job_title']
                                ?? 'Employee'
                            ) ?>
                        </small>
                    </div>
                <?php endif; ?>

                <div class="form-field">
                    <label for="leave-type">
                        Leave type
                    </label>
                    <select
                        id="leave-type"
                        name="leave_type_id"
                        required
                    >
                        <option value="">Select leave type</option>
                        <?php foreach (
                            $leaveTypes as $type
                        ): ?>
                            <?php
                            $typeId = (int) (
                                $type['leave_type_id'] ?? 0
                            );
                            ?>
                            <option
                                value="<?= e($typeId) ?>"
                                <?= $selectedLeaveTypeId
                                    === $typeId
                                        ? 'selected'
                                        : '' ?>
                            >
                                <?= e($type['name'] ?? '') ?>
                                ·
                                <?= e(
                                    $type['annual_entitlement']
                                    ?? '0'
                                ) ?>
                                days/year
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (
                        !empty($errors['leave_type_id'])
                    ): ?>
                        <small class="field-error">
                            <?= e(
                                $errors['leave_type_id']
                            ) ?>
                        </small>
                    <?php endif; ?>
                </div>

                <div class="operations-time-grid">
                    <div class="form-field">
                        <label for="leave-start">
                            Start date
                        </label>
                        <input
                            id="leave-start"
                            name="start_date"
                            type="date"
                            value="<?= e(
                                $old['start_date'] ?? ''
                            ) ?>"
                            required
                        >
                        <?php if (
                            !empty($errors['start_date'])
                        ): ?>
                            <small class="field-error">
                                <?= e($errors['start_date']) ?>
                            </small>
                        <?php endif; ?>
                    </div>
                    <div class="form-field">
                        <label for="leave-end">
                            End date
                        </label>
                        <input
                            id="leave-end"
                            name="end_date"
                            type="date"
                            value="<?= e(
                                $old['end_date'] ?? ''
                            ) ?>"
                            required
                        >
                        <?php if (
                            !empty($errors['end_date'])
                        ): ?>
                            <small class="field-error">
                                <?= e($errors['end_date']) ?>
                            </small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-field">
                    <label for="leave-reason">
                        Reason or supporting note
                    </label>
                    <textarea
                        id="leave-reason"
                        name="reason"
                        rows="4"
                        maxlength="500"
                        placeholder="Optional request context"
                    ><?= e($old['reason'] ?? '') ?></textarea>
                    <?php if (
                        !empty($errors['reason'])
                    ): ?>
                        <small class="field-error">
                            <?= e($errors['reason']) ?>
                        </small>
                    <?php endif; ?>
                </div>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Submit leave request
                </button>
            </form>
        </aside>
    <?php endif; ?>

    <div class="operations-main">
        <section class="card operations-toolbar">
            <div>
                <span class="section-kicker">
                    <?= e($scopeLabel) ?>
                </span>
                <h2>Leave requests and history</h2>
            </div>
            <form
                method="get"
                class="date-control-form"
            >
                <label for="leave-status">
                    Status
                </label>
                <select
                    id="leave-status"
                    name="status"
                    class="date-control"
                >
                    <option value="">All requests</option>
                    <?php foreach (
                        $statuses as $value => $label
                    ): ?>
                        <option
                            value="<?= e($value) ?>"
                            <?= $filterStatus === $value
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button
                    type="submit"
                    class="btn btn-secondary"
                >
                    Apply
                </button>
            </form>
        </section>

        <?php if (
            !empty($decisionErrors['decision'])
            || !empty(
                $decisionErrors['decision_note']
            )
        ): ?>
            <div class="alert alert-danger" role="alert">
                <?= e(
                    $decisionErrors['decision']
                    ?? $decisionErrors['decision_note']
                    ?? ''
                ) ?>
            </div>
        <?php endif; ?>

        <section class="card table-card">
            <div class="table-responsive">
                <table class="data-table operations-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Scope</th>
                            <th>Leave type</th>
                            <th>Period</th>
                            <th>Days</th>
                            <th>Status</th>
                            <th>Reason / decision</th>
                            <?php if ($canApprove): ?>
                                <th>Decision</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($requests === []): ?>
                        <tr>
                            <td
                                colspan="<?= e(
                                    $canApprove ? 8 : 7
                                ) ?>"
                                class="empty-state"
                            >
                                No leave requests match this view.
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
                                            $request['employeeName']
                                            ?? ''
                                        ) ?>
                                    </strong>
                                    <small>
                                        <?= e(
                                            $request[
                                                'employee_number'
                                            ] ?? ''
                                        ) ?>
                                        ·
                                        <?= e(
                                            $request[
                                                'department_name'
                                            ] ?? 'Unassigned'
                                        ) ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="badge badge-muted">
                                        <?= e(
                                            $request['scopeLabel']
                                            ?? 'Company'
                                        ) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= e(
                                        $request['leave_type_name']
                                        ?? ''
                                    ) ?>
                                </td>
                                <td>
                                    <strong>
                                        <?= e($formatDate(
                                            $request['start_date']
                                            ?? ''
                                        )) ?>
                                    </strong>
                                    <small>
                                        to
                                        <?= e($formatDate(
                                            $request['end_date']
                                            ?? ''
                                        )) ?>
                                    </small>
                                </td>
                                <td>
                                    <?= e(
                                        $request['requested_days']
                                        ?? '0'
                                    ) ?>
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
                                <td class="table-note-cell">
                                    <?= e(
                                        $request['reason'] ?? '—'
                                    ) ?>
                                    <?php if (
                                        !empty(
                                            $request['decision_note']
                                        )
                                    ): ?>
                                        <small>
                                            Decision:
                                            <?= e(
                                                $request[
                                                    'decision_note'
                                                ]
                                            ) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <?php if ($canApprove): ?>
                                    <td class="leave-decision-cell">
                                        <?php if (
                                            (
                                                $request[
                                                    'request_status'
                                                ] ?? ''
                                            ) === 'pending'
                                            && !empty(
                                                $request['canDecide']
                                            )
                                        ): ?>
                                            <form
                                                method="post"
                                                action="/office_app/public/hr/leave/decision"
                                                class="leave-decision-form"
                                            >
                                                <?= csrfField() ?>
                                                <input
                                                    type="hidden"
                                                    name="leave_request_id"
                                                    value="<?= e(
                                                        $request[
                                                            'leave_request_id'
                                                        ] ?? ''
                                                    ) ?>"
                                                >
                                                <input
                                                    name="decision_note"
                                                    type="text"
                                                    maxlength="500"
                                                    placeholder="Decision note"
                                                    aria-label="Decision note"
                                                >
                                                <div>
                                                    <button
                                                        type="submit"
                                                        name="decision"
                                                        value="approved"
                                                        class="btn btn-success btn-small"
                                                    >
                                                        Approve
                                                    </button>
                                                    <button
                                                        type="submit"
                                                        name="decision"
                                                        value="rejected"
                                                        class="btn btn-danger btn-small"
                                                    >
                                                        Reject
                                                    </button>
                                                </div>
                                            </form>
                                        <?php elseif (
                                            (
                                                $request[
                                                    'request_status'
                                                ] ?? ''
                                            ) !== 'pending'
                                        ): ?>
                                            <span class="text-muted">
                                                <?= e(
                                                    $request[
                                                        'decided_by_name'
                                                    ] ?? 'Completed'
                                                ) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">
                                                Manager decision
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</section>
