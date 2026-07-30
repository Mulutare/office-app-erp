<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$employee = is_array($data['employee'] ?? null)
    ? $data['employee']
    : [];
$records = is_array($data['records'] ?? null)
    ? $data['records']
    : [];
$today = is_array($data['today'] ?? null)
    ? $data['today']
    : null;
$summary = is_array($data['summary'] ?? null)
    ? $data['summary']
    : [];
$range = is_array($data['range'] ?? null)
    ? $data['range']
    : [];
$notice = is_array($data['notice'] ?? null)
    ? $data['notice']
    : null;
$errors = is_array($data['errors'] ?? null)
    ? $data['errors']
    : [];
$profileRequired = !empty($data['profileRequired']);
$canRecord = !empty($data['canRecord']);
$canCheckIn = $canRecord
    && !empty($data['canCheckIn']);
$canCheckOut = $canRecord
    && !empty($data['canCheckOut']);
$canViewTeam = !empty($data['canViewTeam']);

$formatDate = static function (
    mixed $value
): string {
    if (!is_string($value) || $value === '') {
        return '—';
    }

    $timestamp = strtotime($value);

    return $timestamp === false
        ? '—'
        : date('D, d M Y', $timestamp);
};
?>

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

<section class="attendance-self-hero">
    <div class="attendance-self-identity">
        <span class="section-kicker">
            Employee self service
        </span>
        <h2>
            <?= e(
                $employee['displayName']
                ?? $employee['display_name']
                ?? 'My attendance'
            ) ?>
        </h2>
        <p>
            <?= e(
                $employee['job_title']
                ?? 'Employee profile'
            ) ?>
            <?php if (!empty(
                $employee['department_name']
            )): ?>
                <span aria-hidden="true">·</span>
                <?= e(
                    $employee['department_name']
                ) ?>
            <?php endif; ?>
        </p>
        <?php if (!empty(
            $employee['employee_number']
        )): ?>
            <span class="attendance-employee-number">
                <?= e(
                    $employee['employee_number']
                ) ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="attendance-manager-card">
        <span>Reporting manager</span>
        <strong>
            <?= e(
                $employee['manager_display_name']
                ?? 'Not assigned'
            ) ?>
        </strong>
        <small>
            <?= e(
                $employee['manager_email']
                ?? 'Company administration manages reporting lines.'
            ) ?>
        </small>
    </div>
</section>

<?php if ($profileRequired): ?>
    <section class="alert alert-warning" role="status">
        <strong>Employee profile required</strong>
        <p>
            Your company administrator must link this
            account to an active HR employee profile
            before personal attendance can be used.
        </p>
    </section>
<?php else: ?>
    <section class="attendance-today-grid">
        <article class="card attendance-clock-card">
            <div class="attendance-card-heading">
                <div>
                    <span class="section-kicker">
                        Today
                    </span>
                    <h2>
                        <?= e($formatDate(
                            $data['todayDate'] ?? null
                        )) ?>
                    </h2>
                </div>
                <span class="badge badge-<?= e(
                    $today['statusTone']
                    ?? 'muted'
                ) ?>">
                    <?= e(
                        $today['statusLabel']
                        ?? 'Not recorded'
                    ) ?>
                </span>
            </div>

            <div class="attendance-clock-facts">
                <div>
                    <span>Check-in</span>
                    <strong>
                        <?= e(
                            $today['checkInTime']
                            ?? '—'
                        ) ?>
                    </strong>
                </div>
                <div>
                    <span>Check-out</span>
                    <strong>
                        <?= e(
                            $today['checkOutTime']
                            ?? '—'
                        ) ?>
                    </strong>
                </div>
                <div>
                    <span>Working time</span>
                    <strong>
                        <?= e(
                            $today['workDuration']
                            ?? '0m'
                        ) ?>
                    </strong>
                </div>
            </div>

            <?php if ($canRecord): ?>
                <div class="attendance-clock-actions">
                    <?php if ($canCheckIn): ?>
                        <form
                            method="post"
                            action="/office_app/public/attendance/me/check-in"
                        >
                            <?= csrfField() ?>
                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                Check in now
                            </button>
                        </form>
                    <?php elseif ($canCheckOut): ?>
                        <form
                            method="post"
                            action="/office_app/public/attendance/me/check-out"
                        >
                            <?= csrfField() ?>
                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                Check out now
                            </button>
                        </form>
                    <?php else: ?>
                        <span class="attendance-action-complete">
                            No attendance action is available.
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </article>

        <article class="card attendance-policy-card">
            <span class="section-kicker">
                Good practice
            </span>
            <h2>Record at the point of work</h2>
            <p>
                Check in when work begins and check out
                when the working day ends. HR can review
                exceptions but cannot recover an omitted
                self-service event automatically.
            </p>
            <?php if ($canViewTeam): ?>
                <a
                    href="/office_app/public/attendance/team"
                    class="table-link"
                >
                    Review team attendance
                </a>
            <?php endif; ?>
        </article>
    </section>
<?php endif; ?>

<section class="attendance-period-bar">
    <div>
        <span class="section-kicker">
            Monthly history
        </span>
        <h2><?= e($range['label'] ?? '') ?></h2>
    </div>
    <nav
        class="attendance-period-actions"
        aria-label="Attendance month"
    >
        <a
            href="/office_app/public/attendance/me?month=<?= e(
                $range['previous'] ?? ''
            ) ?>"
            class="btn btn-secondary"
        >
            Previous
        </a>
        <a
            href="/office_app/public/attendance/me?month=<?= e(
                $range['next'] ?? ''
            ) ?>"
            class="btn btn-secondary"
        >
            Next
        </a>
    </nav>
</section>

<section class="attendance-summary-grid">
    <article>
        <span>Recorded days</span>
        <strong>
            <?= e($summary['recorded'] ?? 0) ?>
        </strong>
    </article>
    <article>
        <span>Present</span>
        <strong>
            <?= e($summary['present'] ?? 0) ?>
        </strong>
    </article>
    <article>
        <span>Late arrivals</span>
        <strong>
            <?= e($summary['late'] ?? 0) ?>
        </strong>
    </article>
    <article>
        <span>Working time</span>
        <strong>
            <?= e(
                $summary['workDuration'] ?? '0h'
            ) ?>
        </strong>
    </article>
</section>

<section class="card attendance-history-card">
    <div class="attendance-card-heading">
        <div>
            <span class="section-kicker">
                Personal record
            </span>
            <h2>Attendance activity</h2>
        </div>
        <span class="badge badge-info">
            <?= e(count($records)) ?> entries
        </span>
    </div>

    <?php if ($records === []): ?>
        <div class="attendance-empty-state">
            <strong>No attendance recorded</strong>
            <p>
                There are no personal attendance entries
                for this month.
            </p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table attendance-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Working time</th>
                        <th>Source</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (
                        $records as $record
                    ): ?>
                        <tr>
                            <td>
                                <strong>
                                    <?= e($formatDate(
                                        $record[
                                            'attendance_date'
                                        ] ?? null
                                    )) ?>
                                </strong>
                            </td>
                            <td>
                                <span class="badge badge-<?= e(
                                    $record[
                                        'statusTone'
                                    ] ?? 'muted'
                                ) ?>">
                                    <?= e(
                                        $record[
                                            'statusLabel'
                                        ] ?? ''
                                    ) ?>
                                </span>
                            </td>
                            <td>
                                <?= e(
                                    $record[
                                        'checkInTime'
                                    ] ?? '—'
                                ) ?>
                            </td>
                            <td>
                                <?= e(
                                    $record[
                                        'checkOutTime'
                                    ] ?? '—'
                                ) ?>
                            </td>
                            <td>
                                <?= e(
                                    $record[
                                        'workDuration'
                                    ] ?? '0m'
                                ) ?>
                            </td>
                            <td>
                                <?= e(ucwords(str_replace(
                                    '_',
                                    ' ',
                                    (string) (
                                        $record['source']
                                        ?? ''
                                    )
                                ))) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
