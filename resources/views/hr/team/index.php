<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$reporting = is_array(
    $data['reporting'] ?? null
)
    ? $data['reporting']
    : [];
$reports = is_array($data['reports'] ?? null)
    ? $data['reports']
    : [];
$pendingRequests = is_array(
    $data['pendingRequests'] ?? null
)
    ? $data['pendingRequests']
    : [];
$upcomingRequests = is_array(
    $data['upcomingRequests'] ?? null
)
    ? $data['upcomingRequests']
    : [];
$balances = is_array(
    $data['balances'] ?? null
)
    ? $data['balances']
    : [];
$summary = is_array($data['summary'] ?? null)
    ? $data['summary']
    : [];
$attendanceEnabled = !empty(
    $data['attendanceEnabled']
);
$canApproveTeam = !empty(
    $data['canApproveTeam']
);

$formatDate = static function (
    mixed $value
): string {
    if (!is_string($value) || trim($value) === '') {
        return 'Not scheduled';
    }

    $timestamp = strtotime($value);

    return $timestamp === false
        ? $value
        : date('M j, Y', $timestamp);
};
?>

<nav
    class="workspace-breadcrumb"
    aria-label="Breadcrumb"
>
    <a href="/office_app/public/hr">HR</a>
    <span>/</span>
    <strong>My team</strong>
</nav>

<section class="team-hero">
    <div class="team-hero-copy">
        <span class="section-kicker">
            Reporting workspace
        </span>
        <h2>
            Lead your team with the right context.
        </h2>
        <p>
            Review reporting relationships, availability,
            pending leave and personal balances without
            exposing company-wide HR records.
        </p>
    </div>

    <div class="team-reporting-card">
        <span>You report to</span>
        <?php if (
            !empty(
                $reporting['manager_display_name']
            )
        ): ?>
            <strong>
                <?= e(
                    $reporting[
                        'manager_display_name'
                    ]
                ) ?>
            </strong>
            <small>
                <?= e(
                    $reporting['manager_job_title']
                    ?? $reporting['manager_email']
                    ?? ''
                ) ?>
            </small>
        <?php else: ?>
            <strong>Company leadership</strong>
            <small>
                No reporting manager is assigned.
            </small>
        <?php endif; ?>
    </div>
</section>

<section class="team-summary-grid">
    <article class="team-metric is-primary">
        <span>Direct reports</span>
        <strong>
            <?= e(
                $summary['directReports'] ?? 0
            ) ?>
        </strong>
        <small>Active company assignments</small>
    </article>
    <article class="team-metric">
        <span>Pending approvals</span>
        <strong>
            <?= e(
                $summary['pendingApprovals'] ?? 0
            ) ?>
        </strong>
        <small>Direct-report leave requests</small>
    </article>
    <article class="team-metric">
        <span>
            <?= $attendanceEnabled
                ? 'Attendance recorded'
                : 'Attendance module' ?>
        </span>
        <strong>
            <?= $attendanceEnabled
                ? e(
                    $summary[
                        'attendanceRecorded'
                    ] ?? 0
                )
                : 'Off' ?>
        </strong>
        <small>
            <?= $attendanceEnabled
                ? 'For today'
                : 'Not licensed for this company' ?>
        </small>
    </article>
    <article class="team-metric">
        <span>On leave today</span>
        <strong>
            <?= e(
                $summary['onLeaveToday'] ?? 0
            ) ?>
        </strong>
        <small>Current direct-report absence</small>
    </article>
</section>

<?php if (
    ($summary['profilesMissing'] ?? 0) > 0
): ?>
    <div class="alert alert-warning" role="status">
        <strong>
            Some direct reports need an HR profile.
        </strong>
        <p>
            <?= e(
                $summary['profilesMissing']
            ) ?>
            company account(s) cannot use employee
            leave or attendance until HR links an
            active employee record.
        </p>
    </div>
<?php endif; ?>

<section class="team-dashboard-grid">
    <article class="card team-panel">
        <div class="team-panel-heading">
            <div>
                <span class="section-kicker">
                    Personal entitlement
                </span>
                <h2>My leave balance</h2>
            </div>
            <a
                href="/office_app/public/hr/leave"
                class="table-link"
            >
                Open leave
            </a>
        </div>

        <?php if ($balances === []): ?>
            <div class="team-empty-state">
                <strong>No employee profile linked</strong>
                <p>
                    Ask HR to link your company account
                    to an active employee profile.
                </p>
            </div>
        <?php else: ?>
            <div class="balance-list">
                <?php foreach (
                    $balances as $balance
                ): ?>
                    <div class="balance-item">
                        <div>
                            <strong>
                                <?= e(
                                    $balance['name'] ?? ''
                                ) ?>
                            </strong>
                            <small>
                                <?= e(
                                    $balance['usedDays']
                                    ?? '0.00'
                                ) ?>
                                used of
                                <?= e(
                                    $balance[
                                        'entitlementDays'
                                    ] ?? '0.00'
                                ) ?>
                                days
                            </small>
                        </div>
                        <strong>
                            <?= e(
                                $balance['remainingDays']
                                ?? '0.00'
                            ) ?>
                            <small>remaining</small>
                        </strong>
                        <div
                            class="balance-progress"
                            aria-label="<?= e(
                                $balance['name'] ?? ''
                            ) ?> utilization"
                        >
                            <span style="width: <?= e(
                                $balance['utilization']
                                ?? 0
                            ) ?>%"></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="card team-panel">
        <div class="team-panel-heading">
            <div>
                <span class="section-kicker">
                    Manager queue
                </span>
                <h2>Leave awaiting review</h2>
            </div>
            <?php if (
                $pendingRequests !== []
                && $canApproveTeam
            ): ?>
                <a
                    href="/office_app/public/hr/leave?status=pending"
                    class="table-link"
                >
                    Review requests
                </a>
            <?php endif; ?>
        </div>

        <?php if ($pendingRequests === []): ?>
            <div class="team-empty-state">
                <strong>Approval queue is clear</strong>
                <p>
                    No direct-report leave requests are
                    waiting for your decision.
                </p>
            </div>
        <?php else: ?>
            <div class="team-request-list">
                <?php foreach (
                    $pendingRequests as $request
                ): ?>
                    <div class="team-request-item">
                        <div>
                            <strong>
                                <?= e(
                                    $request[
                                        'employeeName'
                                    ] ?? ''
                                ) ?>
                            </strong>
                            <small>
                                <?= e(
                                    $request[
                                        'leave_type_name'
                                    ] ?? ''
                                ) ?>
                            </small>
                        </div>
                        <span>
                            <?= e($formatDate(
                                $request['start_date']
                                ?? null
                            )) ?>
                            &ndash;
                            <?= e($formatDate(
                                $request['end_date']
                                ?? null
                            )) ?>
                        </span>
                        <span class="badge badge-warning">
                            Pending
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>

<section class="card team-directory-panel">
    <div class="team-panel-heading">
        <div>
            <span class="section-kicker">
                Reporting hierarchy
            </span>
            <h2>Direct reports</h2>
            <p>
                Only users explicitly assigned to you
                inside the active company appear here.
            </p>
        </div>
        <span class="badge badge-info">
            <?= e(count($reports)) ?> people
        </span>
    </div>

    <?php if ($reports === []): ?>
        <div class="team-empty-state">
            <strong>No direct reports assigned</strong>
            <p>
                Your personal leave balance remains
                available above. A company administrator
                controls reporting assignments.
            </p>
        </div>
    <?php else: ?>
        <div class="team-card-grid">
            <?php foreach ($reports as $report): ?>
                <article class="team-person-card">
                    <div class="team-person-avatar">
                        <?= e(strtoupper(substr(
                            (string) (
                                $report['displayName']
                                ?? 'E'
                            ),
                            0,
                            1
                        ))) ?>
                    </div>
                    <div class="team-person-identity">
                        <strong>
                            <?= e(
                                $report['displayName']
                                ?? ''
                            ) ?>
                        </strong>
                        <span>
                            <?= e(
                                $report['job_title']
                                ?? 'Employee profile pending'
                            ) ?>
                        </span>
                        <small>
                            <?= e(
                                $report['department_name']
                                ?? $report['email']
                                ?? ''
                            ) ?>
                        </small>
                    </div>

                    <dl class="team-person-facts">
                        <div>
                            <dt>Attendance</dt>
                            <dd>
                                <?php if (
                                    $attendanceEnabled
                                ): ?>
                                    <span class="badge badge-<?= e(
                                        $report[
                                            'attendanceTone'
                                        ] ?? 'muted'
                                    ) ?>">
                                        <?= e(
                                            $report[
                                                'attendanceLabel'
                                            ] ?? ''
                                        ) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">
                                        Module disabled
                                    </span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div>
                            <dt>Pending leave</dt>
                            <dd>
                                <?= e(
                                    $report[
                                        'pending_leave_count'
                                    ] ?? 0
                                ) ?>
                            </dd>
                        </div>
                        <div>
                            <dt>Next leave</dt>
                            <dd>
                                <?= e($formatDate(
                                    $report[
                                        'next_leave_date'
                                    ] ?? null
                                )) ?>
                            </dd>
                        </div>
                    </dl>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($upcomingRequests !== []): ?>
    <section class="card team-upcoming-panel">
        <div class="team-panel-heading">
            <div>
                <span class="section-kicker">
                    Availability outlook
                </span>
                <h2>Upcoming approved leave</h2>
            </div>
        </div>
        <div class="team-upcoming-list">
            <?php foreach (
                $upcomingRequests as $request
            ): ?>
                <div>
                    <strong>
                        <?= e(
                            $request['employeeName']
                            ?? ''
                        ) ?>
                    </strong>
                    <span>
                        <?= e(
                            $request['leave_type_name']
                            ?? ''
                        ) ?>
                    </span>
                    <span>
                        <?= e($formatDate(
                            $request['start_date']
                            ?? null
                        )) ?>
                        &ndash;
                        <?= e($formatDate(
                            $request['end_date']
                            ?? null
                        )) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
