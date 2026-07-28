<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$date = (string) ($data['date'] ?? date('Y-m-d'));
$records = is_array($data['records'] ?? null)
    ? $data['records']
    : [];
$summary = is_array($data['summary'] ?? null)
    ? $data['summary']
    : [];
$statuses = is_array($data['statuses'] ?? null)
    ? $data['statuses']
    : [];
$canManage = !empty($data['canManage']);
$notice = is_array($data['notice'] ?? null)
    ? $data['notice']
    : null;
$errors = is_array($data['errors'] ?? null)
    ? $data['errors']
    : [];
$old = is_array($data['old'] ?? null)
    ? $data['old']
    : [];
$selectedEmployeeId = (int) (
    $old['employee_id'] ?? 0
);
$selectedStatus = (string) (
    $old['attendance_status'] ?? 'present'
);
$formatDate = static function (string $value): string {
    $timestamp = strtotime($value);

    return $timestamp === false
        ? $value
        : date('D, d M Y', $timestamp);
};
?>

<nav class="workspace-breadcrumb" aria-label="Breadcrumb">
    <a href="/office_app/public/hr">HR</a>
    <span aria-hidden="true">/</span>
    <strong>Attendance</strong>
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

<section class="operations-summary-grid">
    <article class="operations-summary-card is-primary">
        <span>Workforce</span>
        <strong><?= e($summary['total'] ?? 0) ?></strong>
        <small><?= e($formatDate($date)) ?></small>
    </article>
    <article class="operations-summary-card">
        <span>Present</span>
        <strong><?= e($summary['present'] ?? 0) ?></strong>
        <small>Checked in on site</small>
    </article>
    <article class="operations-summary-card">
        <span>Late</span>
        <strong><?= e($summary['late'] ?? 0) ?></strong>
        <small>Arrival exceptions</small>
    </article>
    <article class="operations-summary-card">
        <span>Remote</span>
        <strong><?= e($summary['remote'] ?? 0) ?></strong>
        <small>Working off site</small>
    </article>
    <article class="operations-summary-card">
        <span>Not recorded</span>
        <strong>
            <?= e($summary['not_recorded'] ?? 0) ?>
        </strong>
        <small>Requires follow-up</small>
    </article>
</section>

<section class="operations-layout">
    <?php if ($canManage): ?>
        <aside class="card operations-entry-card">
            <span class="section-kicker">Daily entry</span>
            <h2 class="card-title">
                Record attendance
            </h2>
            <p class="form-help">
                Select an employee and save their status
                for the selected work date.
            </p>

            <form
                method="post"
                action="/office_app/public/attendance/records"
                class="operations-form"
            >
                <?= csrfField() ?>

                <div class="form-field">
                    <label for="attendance-employee">
                        Employee
                    </label>
                    <select
                        id="attendance-employee"
                        name="employee_id"
                        required
                    >
                        <option value="">Select employee</option>
                        <?php foreach ($records as $record): ?>
                            <?php
                            $employeeId = (int) (
                                $record['employee_id'] ?? 0
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
                                    $record['employeeName'] ?? ''
                                ) ?>
                                ·
                                <?= e(
                                    $record['employee_number']
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

                <div class="form-field">
                    <label for="attendance-date">
                        Work date
                    </label>
                    <input
                        id="attendance-date"
                        name="attendance_date"
                        type="date"
                        value="<?= e(
                            $old['attendance_date'] ?? $date
                        ) ?>"
                        required
                    >
                    <?php if (
                        !empty($errors['attendance_date'])
                    ): ?>
                        <small class="field-error">
                            <?= e($errors['attendance_date']) ?>
                        </small>
                    <?php endif; ?>
                </div>

                <div class="form-field">
                    <label for="attendance-status">
                        Status
                    </label>
                    <select
                        id="attendance-status"
                        name="attendance_status"
                        required
                    >
                        <?php foreach (
                            $statuses as $value => $label
                        ): ?>
                            <option
                                value="<?= e($value) ?>"
                                <?= $selectedStatus === $value
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (
                        !empty($errors['attendance_status'])
                    ): ?>
                        <small class="field-error">
                            <?= e(
                                $errors['attendance_status']
                            ) ?>
                        </small>
                    <?php endif; ?>
                </div>

                <div class="operations-time-grid">
                    <div class="form-field">
                        <label for="check-in">
                            Check-in
                        </label>
                        <input
                            id="check-in"
                            name="check_in"
                            type="time"
                            value="<?= e(
                                $old['check_in'] ?? ''
                            ) ?>"
                        >
                        <?php if (
                            !empty($errors['check_in'])
                        ): ?>
                            <small class="field-error">
                                <?= e($errors['check_in']) ?>
                            </small>
                        <?php endif; ?>
                    </div>
                    <div class="form-field">
                        <label for="check-out">
                            Check-out
                        </label>
                        <input
                            id="check-out"
                            name="check_out"
                            type="time"
                            value="<?= e(
                                $old['check_out'] ?? ''
                            ) ?>"
                        >
                        <?php if (
                            !empty($errors['check_out'])
                        ): ?>
                            <small class="field-error">
                                <?= e($errors['check_out']) ?>
                            </small>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-field">
                    <label for="attendance-notes">
                        Notes
                    </label>
                    <textarea
                        id="attendance-notes"
                        name="notes"
                        rows="3"
                        maxlength="500"
                        placeholder="Optional exception or shift note"
                    ><?= e($old['notes'] ?? '') ?></textarea>
                    <?php if (
                        !empty($errors['notes'])
                    ): ?>
                        <small class="field-error">
                            <?= e($errors['notes']) ?>
                        </small>
                    <?php endif; ?>
                </div>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Save attendance
                </button>
            </form>
        </aside>
    <?php endif; ?>

    <div class="operations-main">
        <section class="card operations-toolbar">
            <div>
                <span class="section-kicker">
                    Daily register
                </span>
                <h2><?= e($formatDate($date)) ?></h2>
            </div>
            <form method="get" class="date-control-form">
                <label
                    for="register-date"
                    class="sr-only"
                >
                    Register date
                </label>
                <input
                    id="register-date"
                    class="date-control"
                    name="date"
                    type="date"
                    value="<?= e($date) ?>"
                >
                <button
                    type="submit"
                    class="btn btn-secondary"
                >
                    Load date
                </button>
            </form>
        </section>

        <section class="card table-card">
            <div class="table-responsive">
                <table class="data-table operations-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Status</th>
                            <th>Check-in</th>
                            <th>Check-out</th>
                            <th>Worked</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($records === []): ?>
                        <tr>
                            <td
                                colspan="7"
                                class="empty-state"
                            >
                                No active employees are available.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach (
                            $records as $record
                        ): ?>
                            <tr>
                                <td>
                                    <strong>
                                        <?= e(
                                            $record['employeeName']
                                            ?? ''
                                        ) ?>
                                    </strong>
                                    <small>
                                        <?= e(
                                            $record[
                                                'employee_number'
                                            ] ?? ''
                                        ) ?>
                                    </small>
                                </td>
                                <td>
                                    <?= e(
                                        $record['department_name']
                                        ?? 'Unassigned'
                                    ) ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= e(
                                        $record['statusTone']
                                        ?? 'muted'
                                    ) ?>">
                                        <?= e(
                                            $record['statusLabel']
                                            ?? 'Not recorded'
                                        ) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= e(
                                        $record['checkInTime']
                                        ?? '—'
                                    ) ?>
                                </td>
                                <td>
                                    <?= e(
                                        $record['checkOutTime']
                                        ?? '—'
                                    ) ?>
                                </td>
                                <td>
                                    <?= e(
                                        $record['workDuration']
                                        ?? '—'
                                    ) ?>
                                </td>
                                <td class="table-note-cell">
                                    <?= e(
                                        $record['notes'] ?? '—'
                                    ) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</section>
