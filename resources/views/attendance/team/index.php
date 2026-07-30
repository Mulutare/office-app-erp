<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$people = is_array($data['people'] ?? null)
    ? $data['people']
    : [];
$summary = is_array($data['summary'] ?? null)
    ? $data['summary']
    : [];
$range = is_array($data['range'] ?? null)
    ? $data['range']
    : [];
?>

<section class="attendance-team-hero">
    <div>
        <span class="section-kicker">
            Manager workspace
        </span>
        <h2>Direct-report attendance</h2>
        <p>
            This view is relationship-scoped. It contains
            only active company users assigned directly to
            you by company administration.
        </p>
    </div>
    <a
        href="/office_app/public/attendance/me"
        class="btn btn-secondary"
    >
        My attendance
    </a>
</section>

<section class="attendance-period-bar">
    <div>
        <span class="section-kicker">
            Review period
        </span>
        <h2><?= e($range['label'] ?? '') ?></h2>
    </div>
    <nav
        class="attendance-period-actions"
        aria-label="Team attendance month"
    >
        <a
            href="/office_app/public/attendance/team?month=<?= e(
                $range['previous'] ?? ''
            ) ?>"
            class="btn btn-secondary"
        >
            Previous
        </a>
        <a
            href="/office_app/public/attendance/team?month=<?= e(
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
        <span>Direct reports</span>
        <strong>
            <?= e(
                $summary['directReports'] ?? 0
            ) ?>
        </strong>
    </article>
    <article>
        <span>Recorded entries</span>
        <strong>
            <?= e($summary['recorded'] ?? 0) ?>
        </strong>
    </article>
    <article>
        <span>Exceptions</span>
        <strong>
            <?= e($summary['exceptions'] ?? 0) ?>
        </strong>
    </article>
    <article>
        <span>Profiles pending</span>
        <strong>
            <?= e(
                $summary['profilesMissing'] ?? 0
            ) ?>
        </strong>
    </article>
</section>

<?php if ($people === []): ?>
    <section class="card attendance-empty-state">
        <strong>No direct reports assigned</strong>
        <p>
            Company administration controls reporting
            assignments. No company-wide employee data is
            exposed from this manager workspace.
        </p>
    </section>
<?php else: ?>
    <section class="attendance-team-grid">
        <?php foreach ($people as $person): ?>
            <?php
            $personSummary = is_array(
                $person['summary'] ?? null
            )
                ? $person['summary']
                : [];
            $latest = is_array(
                $person['latest'] ?? null
            )
                ? $person['latest']
                : null;
            ?>
            <article class="card attendance-person-card">
                <div class="attendance-person-heading">
                    <div class="team-person-avatar">
                        <?= e(strtoupper(substr(
                            (string) (
                                $person['displayName']
                                ?? 'E'
                            ),
                            0,
                            1
                        ))) ?>
                    </div>
                    <div>
                        <strong>
                            <?= e(
                                $person['displayName']
                                ?? ''
                            ) ?>
                        </strong>
                        <span>
                            <?= e(
                                $person['jobTitle']
                                ?: 'Employee profile pending'
                            ) ?>
                        </span>
                        <small>
                            <?= e(
                                $person['departmentName']
                                ?: $person['email']
                            ) ?>
                        </small>
                    </div>
                    <span class="badge badge-<?= e(
                        $latest['statusTone']
                        ?? 'muted'
                    ) ?>">
                        <?= e(
                            $latest['statusLabel']
                            ?? 'No entries'
                        ) ?>
                    </span>
                </div>

                <?php if (
                    ($person['employeeId'] ?? 0) < 1
                ): ?>
                    <div class="attendance-profile-warning">
                        Link an active employee profile to
                        enable attendance.
                    </div>
                <?php else: ?>
                    <dl class="attendance-person-summary">
                        <div>
                            <dt>Recorded</dt>
                            <dd>
                                <?= e(
                                    $personSummary[
                                        'recorded'
                                    ] ?? 0
                                ) ?>
                            </dd>
                        </div>
                        <div>
                            <dt>Late</dt>
                            <dd>
                                <?= e(
                                    $personSummary['late']
                                    ?? 0
                                ) ?>
                            </dd>
                        </div>
                        <div>
                            <dt>Absent</dt>
                            <dd>
                                <?= e(
                                    $personSummary[
                                        'absent'
                                    ] ?? 0
                                ) ?>
                            </dd>
                        </div>
                        <div>
                            <dt>Working time</dt>
                            <dd>
                                <?= e(
                                    $personSummary[
                                        'workDuration'
                                    ] ?? '0h'
                                ) ?>
                            </dd>
                        </div>
                    </dl>

                    <?php if (
                        ($person['records'] ?? []) === []
                    ): ?>
                        <p class="attendance-person-empty">
                            No attendance entries in this
                            review period.
                        </p>
                    <?php else: ?>
                        <div class="attendance-recent-list">
                            <?php foreach (
                                array_slice(
                                    $person['records'],
                                    0,
                                    3
                                )
                                as $record
                            ): ?>
                                <div>
                                    <span>
                                        <?= e(substr(
                                            (string) (
                                                $record[
                                                    'attendance_date'
                                                ] ?? ''
                                            ),
                                            0,
                                            10
                                        )) ?>
                                    </span>
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
                                    <strong>
                                        <?= e(
                                            $record[
                                                'workDuration'
                                            ] ?? '0m'
                                        ) ?>
                                    </strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
