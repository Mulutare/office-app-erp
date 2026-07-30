<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$workspace = is_array($data['workspace'] ?? null)
    ? $data['workspace']
    : [];
$calendars = is_array($workspace['calendars'] ?? null)
    ? $workspace['calendars']
    : [];
$selected = is_array($workspace['selected'] ?? null)
    ? $workspace['selected']
    : null;
$days = is_array($workspace['days'] ?? null)
    ? $workspace['days']
    : [];
$holidays = is_array($workspace['holidays'] ?? null)
    ? $workspace['holidays']
    : [];
$employees = is_array($workspace['employees'] ?? null)
    ? $workspace['employees']
    : [];
$assignments = is_array($workspace['assignments'] ?? null)
    ? $workspace['assignments']
    : [];
$weekdays = is_array($workspace['weekdays'] ?? null)
    ? $workspace['weekdays']
    : [];
$timezones = is_array($workspace['timezones'] ?? null)
    ? $workspace['timezones']
    : [];
$year = (int) ($workspace['year'] ?? date('Y'));
$calendarId = (int) ($selected['calendar_id'] ?? 0);
$notice = is_array($data['notice'] ?? null)
    ? $data['notice']
    : null;
$calendarErrors = is_array($data['calendarErrors'] ?? null)
    ? $data['calendarErrors']
    : [];
$calendarOld = is_array($data['calendarOld'] ?? null)
    ? $data['calendarOld']
    : [];
$weekErrors = is_array($data['weekErrors'] ?? null)
    ? $data['weekErrors']
    : [];
$holidayErrors = is_array($data['holidayErrors'] ?? null)
    ? $data['holidayErrors']
    : [];
$holidayOld = is_array($data['holidayOld'] ?? null)
    ? $data['holidayOld']
    : [];
$scheduleErrors = is_array($data['scheduleErrors'] ?? null)
    ? $data['scheduleErrors']
    : [];
$scheduleOld = is_array($data['scheduleOld'] ?? null)
    ? $data['scheduleOld']
    : [];
$formatDate = static function (mixed $value): string {
    $timestamp = is_string($value)
        ? strtotime($value)
        : false;

    return $timestamp === false
        ? '—'
        : date('d M Y', $timestamp);
};
?>

<nav class="workspace-breadcrumb" aria-label="Breadcrumb">
    <a href="/office_app/public/attendance">Attendance</a>
    <span aria-hidden="true">/</span>
    <strong>Workforce calendars</strong>
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

<section class="calendar-command-bar">
    <div>
        <span class="section-kicker">Global workforce time</span>
        <h2>Calendar and schedule control</h2>
        <p>
            Define working weeks by location, record observed
            holidays and assign effective-dated schedules.
        </p>
    </div>

    <details
        class="calendar-create-panel"
        <?= $calendarErrors !== [] ? 'open' : '' ?>
    >
        <summary class="btn btn-primary">Create calendar</summary>
        <form
            method="post"
            action="/office_app/public/attendance/calendars"
            class="calendar-create-form"
        >
            <?= csrfField() ?>
            <?php if (!empty($calendarErrors['form'])): ?>
                <div class="alert alert-danger">
                    <?= e($calendarErrors['form']) ?>
                </div>
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-field">
                    <label for="calendar-code">Code</label>
                    <input
                        id="calendar-code"
                        name="code"
                        value="<?= e($calendarOld['code'] ?? '') ?>"
                        maxlength="40"
                        placeholder="KE_NAIROBI"
                        required
                    >
                    <?php if (!empty($calendarErrors['code'])): ?>
                        <small class="field-error">
                            <?= e($calendarErrors['code']) ?>
                        </small>
                    <?php endif; ?>
                </div>
                <div class="form-field">
                    <label for="calendar-name">Name</label>
                    <input
                        id="calendar-name"
                        name="name"
                        value="<?= e($calendarOld['name'] ?? '') ?>"
                        maxlength="120"
                        placeholder="Kenya · Nairobi office"
                        required
                    >
                    <?php if (!empty($calendarErrors['name'])): ?>
                        <small class="field-error">
                            <?= e($calendarErrors['name']) ?>
                        </small>
                    <?php endif; ?>
                </div>
                <div class="form-field form-field-wide">
                    <label for="calendar-timezone">IANA timezone</label>
                    <select
                        id="calendar-timezone"
                        name="timezone"
                        required
                    >
                        <option value="">Select timezone</option>
                        <?php foreach (
                            $timezones as $value => $label
                        ): ?>
                            <option
                                value="<?= e($value) ?>"
                                <?= ($calendarOld['timezone'] ?? '')
                                    === $value
                                        ? 'selected'
                                        : '' ?>
                            >
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty(
                        $calendarErrors['timezone']
                    )): ?>
                        <small class="field-error">
                            <?= e($calendarErrors['timezone']) ?>
                        </small>
                    <?php endif; ?>
                </div>
                <div class="form-field">
                    <label for="country-code">ISO country code</label>
                    <input
                        id="country-code"
                        name="country_code"
                        value="<?= e(
                            $calendarOld['country_code'] ?? ''
                        ) ?>"
                        maxlength="2"
                        placeholder="KE"
                    >
                </div>
                <div class="form-field">
                    <label for="subdivision-code">
                        State / province code
                    </label>
                    <input
                        id="subdivision-code"
                        name="subdivision_code"
                        value="<?= e(
                            $calendarOld['subdivision_code'] ?? ''
                        ) ?>"
                        maxlength="16"
                        placeholder="Optional"
                    >
                </div>
                <div class="form-field">
                    <label for="week-start">Week begins</label>
                    <select id="week-start" name="week_start">
                        <?php foreach (
                            $weekdays as $value => $label
                        ): ?>
                            <option
                                value="<?= e($value) ?>"
                                <?= (int) (
                                    $calendarOld['week_start'] ?? 1
                                ) === (int) $value
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <label class="checkbox-option">
                    <input
                        type="checkbox"
                        name="is_default"
                        value="1"
                        <?= !empty($calendarOld['is_default'])
                            ? 'checked'
                            : '' ?>
                    >
                    <span>
                        <strong>Company default</strong>
                        <small>
                            Used when an employee has no specific
                            calendar assignment.
                        </small>
                    </span>
                </label>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">
                    Create workforce calendar
                </button>
            </div>
        </form>
    </details>
</section>

<section class="calendar-summary-grid">
    <article>
        <span>Calendars</span>
        <strong><?= e(count($calendars)) ?></strong>
        <small>Location-ready definitions</small>
    </article>
    <article>
        <span>Scheduled employees</span>
        <strong><?= e(count($assignments)) ?></strong>
        <small>Effective assignments</small>
    </article>
    <article>
        <span><?= e($year) ?> holidays</span>
        <strong><?= e(count($holidays)) ?></strong>
        <small>Public and company dates</small>
    </article>
    <article>
        <span>Time standard</span>
        <strong class="calendar-summary-text">
            <?= e($selected['timezone'] ?? 'Not configured') ?>
        </strong>
        <small>IANA timezone</small>
    </article>
</section>

<?php if ($calendars === []): ?>
    <section class="card calendar-empty-state">
        <span class="section-kicker">Start here</span>
        <h2>Create the first company calendar</h2>
        <p>
            The first calendar can become the company default.
            It starts with a Monday–Friday workweek.
        </p>
    </section>
<?php else: ?>
    <nav
        class="calendar-switcher"
        aria-label="Workforce calendars"
    >
        <?php foreach ($calendars as $calendar): ?>
            <?php
            $itemId = (int) ($calendar['calendar_id'] ?? 0);
            ?>
            <a
                href="/office_app/public/attendance/calendars?calendar=<?= e(
                    $itemId
                ) ?>&year=<?= e($year) ?>"
                class="<?= $itemId === $calendarId
                    ? 'is-active'
                    : '' ?>"
            >
                <strong><?= e($calendar['name'] ?? '') ?></strong>
                <span>
                    <?= e($calendar['country_code'] ?? 'GLOBAL') ?>
                    · <?= e($calendar['timezone'] ?? '') ?>
                </span>
                <?php if (!empty($calendar['is_default'])): ?>
                    <small>Company default</small>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <section class="calendar-management-grid">
        <article class="card calendar-week-card">
            <div class="calendar-section-heading">
                <div>
                    <span class="section-kicker">Standard week</span>
                    <h2><?= e($selected['name'] ?? '') ?></h2>
                    <p>
                        Night shifts are supported; an end time
                        earlier than the start means the next day.
                    </p>
                </div>
                <?php if (!empty($selected['is_default'])): ?>
                    <span class="badge badge-success">Default</span>
                <?php endif; ?>
            </div>

            <?php if (!empty($weekErrors['form'])): ?>
                <div class="alert alert-danger">
                    <?= e($weekErrors['form']) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($weekErrors['days'])): ?>
                <div class="alert alert-danger">
                    <?= e($weekErrors['days']) ?>
                </div>
            <?php endif; ?>

            <form
                method="post"
                action="/office_app/public/attendance/calendars/week"
            >
                <?= csrfField() ?>
                <input
                    type="hidden"
                    name="calendar_id"
                    value="<?= e($calendarId) ?>"
                >
                <div class="calendar-week-list">
                    <?php foreach ($days as $day): ?>
                        <?php
                        $weekday = (int) (
                            $day['iso_weekday'] ?? 0
                        );
                        ?>
                        <div class="calendar-day-row">
                            <label class="calendar-day-toggle">
                                <input
                                    type="checkbox"
                                    name="days[<?= e(
                                        $weekday
                                    ) ?>][working_day]"
                                    value="1"
                                    <?= !empty($day['working_day'])
                                        ? 'checked'
                                        : '' ?>
                                >
                                <span><?= e($day['label'] ?? '') ?></span>
                            </label>
                            <label class="form-field">
                                <span>Start</span>
                                <input
                                    type="time"
                                    name="days[<?= e(
                                        $weekday
                                    ) ?>][start_time]"
                                    value="<?= e(
                                        $day['start_time'] ?? ''
                                    ) ?>"
                                >
                            </label>
                            <label class="form-field">
                                <span>End</span>
                                <input
                                    type="time"
                                    name="days[<?= e(
                                        $weekday
                                    ) ?>][end_time]"
                                    value="<?= e(
                                        $day['end_time'] ?? ''
                                    ) ?>"
                                >
                            </label>
                            <label class="form-field">
                                <span>Break</span>
                                <input
                                    type="number"
                                    name="days[<?= e(
                                        $weekday
                                    ) ?>][break_minutes]"
                                    value="<?= e(
                                        $day['break_minutes'] ?? 0
                                    ) ?>"
                                    min="0"
                                    max="480"
                                    step="5"
                                >
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        Save standard week
                    </button>
                </div>
            </form>
        </article>

        <aside class="calendar-side-stack">
            <article class="card">
                <span class="section-kicker">Add holiday</span>
                <h2 class="card-title">Public or company day</h2>
                <?php if (!empty($holidayErrors['form'])): ?>
                    <div class="alert alert-danger">
                        <?= e($holidayErrors['form']) ?>
                    </div>
                <?php endif; ?>
                <form
                    method="post"
                    action="/office_app/public/attendance/calendars/holidays"
                    class="operations-form"
                >
                    <?= csrfField() ?>
                    <input
                        type="hidden"
                        name="calendar_id"
                        value="<?= e($calendarId) ?>"
                    >
                    <div class="form-field">
                        <label for="holiday-date">Date</label>
                        <input
                            id="holiday-date"
                            name="holiday_date"
                            type="date"
                            value="<?= e(
                                $holidayOld['holiday_date'] ?? ''
                            ) ?>"
                            required
                        >
                    </div>
                    <div class="form-field">
                        <label for="holiday-name">Holiday name</label>
                        <input
                            id="holiday-name"
                            name="name"
                            value="<?= e($holidayOld['name'] ?? '') ?>"
                            maxlength="150"
                            required
                        >
                    </div>
                    <div class="calendar-form-pair">
                        <div class="form-field">
                            <label for="holiday-type">Type</label>
                            <select
                                id="holiday-type"
                                name="holiday_type"
                            >
                                <option value="public">
                                    Public holiday
                                </option>
                                <option value="company">
                                    Company holiday
                                </option>
                            </select>
                        </div>
                        <div class="form-field">
                            <label for="day-portion">Duration</label>
                            <select
                                id="day-portion"
                                name="day_portion"
                            >
                                <option value="full">Full day</option>
                                <option value="am">Morning</option>
                                <option value="pm">Afternoon</option>
                            </select>
                        </div>
                    </div>
                    <label class="checkbox-option">
                        <input
                            type="checkbox"
                            name="observed"
                            value="1"
                        >
                        <span>
                            <strong>Observed date</strong>
                            <small>
                                Mark a substitute public-holiday date.
                            </small>
                        </span>
                    </label>
                    <div class="form-field">
                        <label for="holiday-description">Note</label>
                        <textarea
                            id="holiday-description"
                            name="description"
                            rows="2"
                            maxlength="500"
                        ><?= e(
                            $holidayOld['description'] ?? ''
                        ) ?></textarea>
                    </div>
                    <?php foreach ($holidayErrors as $error): ?>
                        <?php if (
                            is_string($error)
                            && $error !== (
                                $holidayErrors['form'] ?? null
                            )
                        ): ?>
                            <small class="field-error">
                                <?= e($error) ?>
                            </small>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-primary">
                        Add holiday
                    </button>
                </form>
            </article>

            <article class="card">
                <span class="section-kicker">Assign schedule</span>
                <h2 class="card-title">
                    Effective employee calendar
                </h2>
                <?php if (!empty($scheduleErrors['form'])): ?>
                    <div class="alert alert-danger">
                        <?= e($scheduleErrors['form']) ?>
                    </div>
                <?php endif; ?>
                <form
                    method="post"
                    action="/office_app/public/attendance/calendars/schedules"
                    class="operations-form"
                >
                    <?= csrfField() ?>
                    <input
                        type="hidden"
                        name="calendar_id"
                        value="<?= e($calendarId) ?>"
                    >
                    <div class="form-field">
                        <label for="schedule-employee">Employee</label>
                        <select
                            id="schedule-employee"
                            name="employee_id"
                            required
                        >
                            <option value="">Select employee</option>
                            <?php foreach ($employees as $employee): ?>
                                <option
                                    value="<?= e(
                                        $employee['employee_id'] ?? 0
                                    ) ?>"
                                    <?= (int) (
                                        $scheduleOld['employee_id'] ?? 0
                                    ) === (int) (
                                        $employee['employee_id'] ?? 0
                                    )
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= e(
                                        $employee['displayName'] ?? ''
                                    ) ?>
                                    · <?= e(
                                        $employee['employee_number']
                                        ?? ''
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="calendar-form-pair">
                        <div class="form-field">
                            <label for="effective-from">
                                Effective from
                            </label>
                            <input
                                id="effective-from"
                                name="effective_from"
                                type="date"
                                value="<?= e(
                                    $scheduleOld['effective_from']
                                    ?? date('Y-m-d')
                                ) ?>"
                                required
                            >
                        </div>
                        <div class="form-field">
                            <label for="effective-to">Ends</label>
                            <input
                                id="effective-to"
                                name="effective_to"
                                type="date"
                                value="<?= e(
                                    $scheduleOld['effective_to'] ?? ''
                                ) ?>"
                            >
                        </div>
                    </div>
                    <?php foreach ($scheduleErrors as $error): ?>
                        <?php if (is_string($error)): ?>
                            <small class="field-error">
                                <?= e($error) ?>
                            </small>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-primary">
                        Assign calendar
                    </button>
                </form>
            </article>
        </aside>
    </section>

    <section class="calendar-lists-grid">
        <article class="card table-card">
            <div class="table-summary">
                <div>
                    <span class="section-kicker"><?= e($year) ?></span>
                    <strong>Holiday register</strong>
                </div>
                <form method="get">
                    <input
                        type="hidden"
                        name="calendar"
                        value="<?= e($calendarId) ?>"
                    >
                    <select
                        name="year"
                        aria-label="Holiday year"
                        onchange="this.form.submit()"
                    >
                        <?php for (
                            $optionYear = $year - 2;
                            $optionYear <= $year + 2;
                            $optionYear++
                        ): ?>
                            <option
                                value="<?= e($optionYear) ?>"
                                <?= $optionYear === $year
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= e($optionYear) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </form>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Holiday</th>
                            <th>Type</th>
                            <th>Duration</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($holidays === []): ?>
                        <tr>
                            <td colspan="4" class="empty-state">
                                No holidays are recorded for this year.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($holidays as $holiday): ?>
                            <tr>
                                <td>
                                    <strong>
                                        <?= e($formatDate(
                                            $holiday['holiday_date']
                                            ?? null
                                        )) ?>
                                    </strong>
                                </td>
                                <td>
                                    <?= e($holiday['name'] ?? '') ?>
                                    <?php if (!empty(
                                        $holiday['observed']
                                    )): ?>
                                        <small>Observed</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-info">
                                        <?= e(ucfirst((string) (
                                            $holiday['holiday_type']
                                            ?? ''
                                        ))) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= e(ucfirst((string) (
                                        $holiday['day_portion']
                                        ?? 'full'
                                    ))) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="card table-card">
            <div class="table-summary">
                <div>
                    <span class="section-kicker">Effective dated</span>
                    <strong>Employee schedules</strong>
                </div>
                <span><?= e(count($assignments)) ?> active</span>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Calendar</th>
                            <th>Effective</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($assignments === []): ?>
                        <tr>
                            <td colspan="3" class="empty-state">
                                Employees without assignments use
                                the company default calendar.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($assignments as $assignment): ?>
                            <tr>
                                <td>
                                    <strong>
                                        <?= e(
                                            $assignment['employeeName']
                                            ?? ''
                                        ) ?>
                                    </strong>
                                    <small>
                                        <?= e(
                                            $assignment['employee_number']
                                            ?? ''
                                        ) ?>
                                    </small>
                                </td>
                                <td>
                                    <?= e(
                                        $assignment['calendar_name']
                                        ?? ''
                                    ) ?>
                                    <small>
                                        <?= e(
                                            $assignment['timezone']
                                            ?? ''
                                        ) ?>
                                    </small>
                                </td>
                                <td>
                                    <?= e($formatDate(
                                        $assignment['effective_from']
                                        ?? null
                                    )) ?>
                                    <small>
                                        to
                                        <?= e(empty(
                                            $assignment['effective_to']
                                        )
                                            ? 'Open ended'
                                            : $formatDate(
                                                $assignment[
                                                    'effective_to'
                                                ]
                                            )) ?>
                                    </small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>
<?php endif; ?>

<section class="card calendar-delivery-note">
    <div>
        <span class="section-kicker">Server generated</span>
        <h2>Durable attendance notifications</h2>
        <p>
            The server dispatcher stores due reminders in each
            employee’s private inbox even when their browser is
            closed. Browser alerts remain an optional live convenience.
        </p>
    </div>
    <code>php bin/queue-attendance-notifications.php</code>
</section>
