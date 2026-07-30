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
$reminderErrors = is_array(
    $data['reminderErrors'] ?? null
)
    ? $data['reminderErrors']
    : [];
$reminderSettings = is_array(
    $data['reminderSettings'] ?? null
)
    ? $data['reminderSettings']
    : [];
$attendanceNotification = is_array(
    $data['attendanceNotification'] ?? null
)
    ? $data['attendanceNotification']
    : [];
$workSchedule = is_array(
    $data['workSchedule'] ?? null
)
    ? $data['workSchedule']
    : null;
$serverNotifications = is_array(
    $data['serverNotifications'] ?? null
)
    ? $data['serverNotifications']
    : [];
$workdayOptions = is_array(
    $data['workdayOptions'] ?? null
)
    ? $data['workdayOptions']
    : [];
$reminderLeadOptions = is_array(
    $data['reminderLeadOptions'] ?? null
)
    ? $data['reminderLeadOptions']
    : [];
$timezoneOptions = is_array(
    $data['timezoneOptions'] ?? null
)
    ? $data['timezoneOptions']
    : [];
$reminderOld = is_array(
    $data['reminderOld'] ?? null
)
    ? $data['reminderOld']
    : [];
$selectedWorkdays = is_array(
    $reminderOld['workdays'] ?? null
)
    ? array_map('intval', $reminderOld['workdays'])
    : array_map(
        'intval',
        is_array(
            $reminderSettings['workdays'] ?? null
        )
            ? $reminderSettings['workdays']
            : []
    );
$reminderTimezone = (string) (
    $reminderOld['timezone']
    ?? $reminderSettings['timezone']
    ?? 'UTC'
);
$checkInEnabled = array_key_exists(
    'check_in_enabled',
    $reminderOld
)
    ? !empty($reminderOld['check_in_enabled'])
    : !empty($reminderSettings['checkInEnabled']);
$checkOutEnabled = array_key_exists(
    'check_out_enabled',
    $reminderOld
)
    ? !empty($reminderOld['check_out_enabled'])
    : !empty($reminderSettings['checkOutEnabled']);
$browserEnabled = array_key_exists(
    'browser_notifications_enabled',
    $reminderOld
)
    ? !empty(
        $reminderOld[
            'browser_notifications_enabled'
        ]
    )
    : !empty($reminderSettings['browserEnabled']);
$checkInTime = (string) (
    $reminderOld['check_in_time']
    ?? $reminderSettings['checkInTime']
    ?? '08:30'
);
$checkOutTime = (string) (
    $reminderOld['check_out_time']
    ?? $reminderSettings['checkOutTime']
    ?? '17:30'
);
$leadMinutes = (int) (
    $reminderOld['reminder_lead_minutes']
    ?? $reminderSettings['leadMinutes']
    ?? 10
);
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

<?php if (!empty($reminderErrors['form'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($reminderErrors['form']) ?>
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
    <section
        class="attendance-reminder-banner is-<?= e(
            $attendanceNotification['tone']
            ?? 'muted'
        ) ?>"
        data-attendance-notification
        data-browser-enabled="<?= $browserEnabled
            ? '1'
            : '0' ?>"
        data-notify-at="<?= e(
            $attendanceNotification['notifyAtIso']
            ?? ''
        ) ?>"
        data-notification-title="<?= e(
            $attendanceNotification['title']
            ?? ''
        ) ?>"
        data-notification-body="<?= e(
            $attendanceNotification['message']
            ?? ''
        ) ?>"
        data-notification-key="<?= e(
            $attendanceNotification[
                'notificationKey'
            ] ?? ''
        ) ?>"
    >
        <div class="attendance-reminder-icon" aria-hidden="true">
            <?= (
                $attendanceNotification['status']
                ?? ''
            ) === 'due'
                ? '!'
                : 'AT' ?>
        </div>
        <div>
            <span class="section-kicker">
                Personal attendance notification
            </span>
            <h2>
                <?= e(
                    $attendanceNotification['title']
                    ?? 'Personal reminders'
                ) ?>
            </h2>
            <p>
                <?= e(
                    $attendanceNotification['message']
                    ?? ''
                ) ?>
            </p>
        </div>
        <div class="attendance-reminder-context">
            <span>
                <?= e(
                    $attendanceNotification['timezone']
                    ?? $reminderTimezone
                ) ?>
            </span>
            <?php if (!empty(
                $attendanceNotification[
                    'scheduledTime'
                ]
            )): ?>
                <strong>
                    <?= e(
                        $attendanceNotification[
                            'scheduledTime'
                        ]
                    ) ?>
                </strong>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($workSchedule !== null): ?>
        <section class="attendance-schedule-strip">
            <div>
                <span>Assigned calendar</span>
                <strong>
                    <?= e(
                        $workSchedule['calendarName']
                        ?? 'Company calendar'
                    ) ?>
                </strong>
            </div>
            <div>
                <span>Today</span>
                <strong>
                    <?php if (is_array(
                        $workSchedule['holiday'] ?? null
                    )): ?>
                        <?= e(
                            $workSchedule['holiday']['name']
                            ?? 'Holiday'
                        ) ?>
                    <?php elseif (!empty(
                        $workSchedule['workingDay']
                    )): ?>
                        <?= e(
                            $workSchedule['startTime']
                            ?? '—'
                        ) ?>
                        – <?= e(
                            $workSchedule['endTime']
                            ?? '—'
                        ) ?>
                    <?php else: ?>
                        Non-working day
                    <?php endif; ?>
                </strong>
            </div>
            <div>
                <span>Location time</span>
                <strong>
                    <?= e(
                        $workSchedule['timezone']
                        ?? $reminderTimezone
                    ) ?>
                </strong>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($serverNotifications !== []): ?>
        <section class="card attendance-inbox">
            <div class="attendance-card-heading">
                <div>
                    <span class="section-kicker">
                        Server notification inbox
                    </span>
                    <h2>Attendance alerts</h2>
                </div>
                <span class="badge badge-info">
                    <?= e(count(array_filter(
                        $serverNotifications,
                        static fn (array $item): bool =>
                            !empty($item['unread'])
                    ))) ?> unread
                </span>
            </div>
            <div class="attendance-inbox-list">
                <?php foreach (
                    $serverNotifications as $item
                ): ?>
                    <article class="<?= !empty(
                        $item['unread']
                    ) ? 'is-unread' : '' ?>">
                        <div>
                            <strong>
                                <?= e($item['title'] ?? '') ?>
                            </strong>
                            <p><?= e($item['body'] ?? '') ?></p>
                            <small>
                                <?= e(
                                    $item['scheduledLabel']
                                    ?? ''
                                ) ?>
                                · Server generated
                            </small>
                        </div>
                        <?php if (!empty($item['unread'])): ?>
                            <form
                                method="post"
                                action="/office_app/public/attendance/me/notifications/read"
                            >
                                <?= csrfField() ?>
                                <input
                                    type="hidden"
                                    name="notification_id"
                                    value="<?= e(
                                        $item[
                                            'notification_id'
                                        ] ?? 0
                                    ) ?>"
                                >
                                <button
                                    type="submit"
                                    class="btn btn-secondary btn-small"
                                >
                                    Mark read
                                </button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

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

    <details
        class="card attendance-reminder-settings"
        <?= $reminderErrors !== []
            ? 'open'
            : '' ?>
    >
        <summary>
            <div>
                <span class="section-kicker">
                    My notification settings
                </span>
                <strong>
                    Configure personal attendance reminders
                </strong>
            </div>
            <span>Open settings</span>
        </summary>

        <form
            method="post"
            action="/office_app/public/attendance/me/reminders"
            class="attendance-reminder-form"
        >
            <?= csrfField() ?>

            <?php if (!empty(
                $reminderErrors['reminders']
            )): ?>
                <div
                    class="field-error attendance-reminder-wide"
                >
                    <?= e(
                        $reminderErrors['reminders']
                    ) ?>
                </div>
            <?php endif; ?>

            <fieldset class="attendance-workday-fieldset">
                <legend>My working days</legend>
                <p>
                    Reminders run only on the days you
                    select.
                </p>
                <div class="attendance-workday-grid">
                    <?php foreach (
                        $workdayOptions
                        as $day => $dayLabel
                    ): ?>
                        <label>
                            <input
                                type="checkbox"
                                name="workdays[]"
                                value="<?= e($day) ?>"
                                <?= in_array(
                                    (int) $day,
                                    $selectedWorkdays,
                                    true
                                )
                                    ? 'checked'
                                    : '' ?>
                            >
                            <span>
                                <?= e(
                                    substr(
                                        (string) $dayLabel,
                                        0,
                                        3
                                    )
                                ) ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty(
                    $reminderErrors['workdays']
                )): ?>
                    <small class="field-error">
                        <?= e(
                            $reminderErrors['workdays']
                        ) ?>
                    </small>
                <?php endif; ?>
            </fieldset>

            <div class="form-field">
                <label for="attendance-reminder-timezone">
                    My timezone
                </label>
                <select
                    id="attendance-reminder-timezone"
                    name="timezone"
                    required
                >
                    <?php foreach (
                        $timezoneOptions
                        as $timezone => $timezoneLabel
                    ): ?>
                        <option
                            value="<?= e($timezone) ?>"
                            <?= $reminderTimezone
                                === $timezone
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($timezoneLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty(
                    $reminderErrors['timezone']
                )): ?>
                    <small class="field-error">
                        <?= e(
                            $reminderErrors['timezone']
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="attendance-reminder-time-card">
                <label>
                    <input
                        type="checkbox"
                        name="check_in_enabled"
                        value="1"
                        <?= $checkInEnabled
                            ? 'checked'
                            : '' ?>
                    >
                    <span>
                        <strong>Check-in reminder</strong>
                        <small>
                            Alert me before work begins.
                        </small>
                    </span>
                </label>
                <input
                    type="time"
                    name="check_in_time"
                    value="<?= e($checkInTime) ?>"
                    required
                    aria-label="Check-in reminder time"
                >
                <?php if (!empty(
                    $reminderErrors['check_in_time']
                )): ?>
                    <small class="field-error">
                        <?= e(
                            $reminderErrors[
                                'check_in_time'
                            ]
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="attendance-reminder-time-card">
                <label>
                    <input
                        type="checkbox"
                        name="check_out_enabled"
                        value="1"
                        <?= $checkOutEnabled
                            ? 'checked'
                            : '' ?>
                    >
                    <span>
                        <strong>Check-out reminder</strong>
                        <small>
                            Alert me when work should end.
                        </small>
                    </span>
                </label>
                <input
                    type="time"
                    name="check_out_time"
                    value="<?= e($checkOutTime) ?>"
                    required
                    aria-label="Check-out reminder time"
                >
                <?php if (!empty(
                    $reminderErrors['check_out_time']
                )): ?>
                    <small class="field-error">
                        <?= e(
                            $reminderErrors[
                                'check_out_time'
                            ]
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="attendance-reminder-lead">
                    Notify me
                </label>
                <select
                    id="attendance-reminder-lead"
                    name="reminder_lead_minutes"
                    required
                >
                    <?php foreach (
                        $reminderLeadOptions
                        as $minutes => $leadLabel
                    ): ?>
                        <option
                            value="<?= e($minutes) ?>"
                            <?= $leadMinutes
                                === (int) $minutes
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($leadLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty(
                    $reminderErrors[
                        'reminder_lead_minutes'
                    ]
                )): ?>
                    <small class="field-error">
                        <?= e(
                            $reminderErrors[
                                'reminder_lead_minutes'
                            ]
                        ) ?>
                    </small>
                <?php endif; ?>
            </div>

            <div class="attendance-browser-option">
                <label>
                    <input
                        type="checkbox"
                        name="browser_notifications_enabled"
                        value="1"
                        data-attendance-browser-checkbox
                        <?= $browserEnabled
                            ? 'checked'
                            : '' ?>
                    >
                    <span>
                        <strong>
                            Browser alert while OfficeApp is open
                        </strong>
                        <small>
                            Requires browser permission and HTTPS
                            outside localhost.
                        </small>
                    </span>
                </label>
                <button
                    type="button"
                    class="btn btn-secondary btn-small"
                    data-enable-attendance-browser
                >
                    Enable browser alerts
                </button>
                <small
                    class="attendance-browser-status"
                    data-attendance-browser-status
                    aria-live="polite"
                ></small>
            </div>

            <div class="form-actions attendance-reminder-wide">
                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    Save my reminders
                </button>
            </div>
        </form>
    </details>
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
