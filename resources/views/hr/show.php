<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$employee = is_array(
    $data['employee'] ?? null
)
    ? $data['employee']
    : [];
$directReports = is_array(
    $data['directReports'] ?? null
)
    ? $data['directReports']
    : [];
$currentPosition = is_array(
    $data['currentPosition'] ?? null
)
    ? $data['currentPosition']
    : null;
$positionHistory = is_array(
    $data['positionHistory'] ?? null
)
    ? $data['positionHistory']
    : [];
$canManage = !empty($data['canManage']);
$canManageUsers = !empty(
    $data['canManageUsers']
);
$notice = is_array($data['notice'] ?? null)
    ? $data['notice']
    : null;

$formatDate = static function (mixed $value): string {
    if (!is_string($value) || trim($value) === '') {
        return 'Not recorded';
    }

    $timestamp = strtotime($value);

    return $timestamp === false
        ? $value
        : date('M j, Y', $timestamp);
};

$initial = strtoupper(substr(
    (string) (
        $employee['displayName'] ?? 'E'
    ),
    0,
    1
));
?>

<?php if ($notice !== null): ?>
    <div class="alert alert-success" role="status">
        <?= e($notice['message'] ?? '') ?>
    </div>
<?php endif; ?>

<div class="details-toolbar">
    <a
        href="/office_app/public/hr"
        class="btn btn-secondary"
    >
        Back to employees
    </a>

    <div class="details-actions">
        <a
            href="/office_app/public/hr/employees/activity?id=<?= e(
                $employee['employee_id'] ?? 0
            ) ?>"
            class="btn btn-secondary"
        >
            View activity
        </a>

        <?php if ($canManage): ?>
            <a
                href="/office_app/public/hr/employees/position?id=<?= e(
                    $employee['employee_id'] ?? 0
                ) ?>"
                class="btn btn-secondary"
            >
                <?= $currentPosition === null
                    ? 'Assign position'
                    : 'Change position' ?>
            </a>
            <a
                href="/office_app/public/hr/employees/edit?id=<?= e(
                    $employee['employee_id'] ?? 0
                ) ?>"
                class="btn btn-primary"
            >
                Edit employee
            </a>
        <?php endif; ?>
    </div>
</div>

<section class="card profile-summary-card">
    <div class="profile-identity">
        <div class="profile-avatar" aria-hidden="true">
            <?= e($initial) ?>
        </div>
        <div>
            <h2 class="card-title">
                <?= e(
                    $employee['displayName'] ?? ''
                ) ?>
            </h2>
            <p class="profile-username">
                <?= e(
                    $employee['employee_number']
                    ?? ''
                ) ?>
                &middot;
                <?= e(
                    $employee['job_title'] ?? ''
                ) ?>
            </p>
        </div>
    </div>

    <span class="badge badge-<?= e(
        $employee['statusTone'] ?? 'muted'
    ) ?>">
        <?= e(
            $employee['statusLabel'] ?? ''
        ) ?>
    </span>
</section>

<div class="employee-profile-grid">
    <section class="card details-card">
        <h2 class="card-title">
            Employment information
        </h2>
        <dl class="metadata-list">
            <div>
                <dt>Legal name</dt>
                <dd>
                    <?= e(
                        $employee['fullName'] ?? ''
                    ) ?>
                </dd>
            </div>
            <div>
                <dt>Employment type</dt>
                <dd>
                    <?= e(
                        $employee['typeLabel'] ?? ''
                    ) ?>
                </dd>
            </div>
            <div>
                <dt>Hire date</dt>
                <dd>
                    <?= e($formatDate(
                        $employee['hire_date'] ?? null
                    )) ?>
                </dd>
            </div>
            <div>
                <dt>Termination date</dt>
                <dd>
                    <?= e($formatDate(
                        $employee['termination_date']
                        ?? null
                    )) ?>
                </dd>
            </div>
        </dl>
    </section>

    <section class="card details-card">
        <h2 class="card-title">
            Organization
        </h2>
        <dl class="metadata-list">
            <div>
                <dt>Department</dt>
                <dd>
                    <?= e(
                        $employee['department_name']
                        ?? 'Unassigned'
                    ) ?>
                </dd>
            </div>
            <div>
                <dt>Department code</dt>
                <dd>
                    <?= e(
                        $employee['department_code']
                        ?? 'Not assigned'
                    ) ?>
                </dd>
            </div>
            <div>
                <dt>Manager</dt>
                <dd>
                    <?php if (!empty(
                        $employee['manager_employee_id']
                    )): ?>
                        <a
                            href="/office_app/public/hr/employees/view?id=<?= e(
                                $employee[
                                    'manager_employee_id'
                                ]
                            ) ?>"
                            class="table-link"
                        >
                            <?= e(
                                $employee['manager_name']
                                ?? 'Employee manager'
                            ) ?>
                        </a>
                    <?php else: ?>
                        Not assigned
                    <?php endif; ?>
                </dd>
            </div>
        </dl>
    </section>

    <section class="card details-card">
        <h2 class="card-title">
            Work contact
        </h2>
        <dl class="metadata-list">
            <div>
                <dt>Email</dt>
                <dd>
                    <a
                        href="mailto:<?= e(
                            $employee['work_email'] ?? ''
                        ) ?>"
                    >
                        <?= e(
                            $employee['work_email']
                            ?? ''
                        ) ?>
                    </a>
                </dd>
            </div>
            <div>
                <dt>Phone</dt>
                <dd>
                    <?= e(
                        $employee['work_phone']
                        ?? 'Not recorded'
                    ) ?>
                </dd>
            </div>
        </dl>
    </section>

    <section class="card details-card">
        <h2 class="card-title">
            ERP account
        </h2>
        <?php if (empty($employee['user_id'])): ?>
            <p class="details-empty">
                No ERP account is linked.
            </p>
        <?php else: ?>
            <dl class="metadata-list">
                <div>
                    <dt>Username</dt>
                    <dd>
                        <?php if ($canManageUsers): ?>
                            <a
                                href="/office_app/public/administration/users/view?id=<?= e(
                                    $employee['user_id']
                                ) ?>"
                                class="table-link"
                            >
                                @<?= e(
                                    $employee['username']
                                    ?? ''
                                ) ?>
                            </a>
                        <?php else: ?>
                            @<?= e(
                                $employee['username'] ?? ''
                            ) ?>
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt>Account status</dt>
                    <dd>
                        <?= !empty(
                            $employee['account_active']
                        )
                            ? 'Active'
                            : 'Inactive' ?>
                    </dd>
                </div>
                <div>
                    <dt>Last login</dt>
                    <dd>
                        <?= e($formatDate(
                            $employee[
                                'account_last_login_at'
                            ] ?? null
                        )) ?>
                    </dd>
                </div>
            </dl>
        <?php endif; ?>
    </section>
</div>

<section class="card details-section assignment-history">
    <div class="section-heading">
        <div>
            <span class="eyebrow">
                Workforce placement
            </span>
            <h2 class="card-title">
                Position assignment history
            </h2>
            <p>
                Effective-dated placement against approved
                company headcount.
            </p>
        </div>
        <span class="count-pill">
            <?= e(count($positionHistory)) ?>
        </span>
    </div>

    <?php if ($currentPosition === null): ?>
        <div class="assignment-empty">
            <div>
                <strong>No approved position assigned</strong>
                <p>
                    The employee record still retains its
                    legacy department and job-title fields.
                </p>
            </div>
            <?php if ($canManage): ?>
                <a
                    href="/office_app/public/hr/employees/position?id=<?= e(
                        $employee['employee_id'] ?? 0
                    ) ?>"
                    class="btn btn-primary"
                >
                    Assign position
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <article class="current-assignment-card">
            <div>
                <span class="badge badge-success">
                    Current
                </span>
                <h3>
                    <?= e(
                        $currentPosition[
                            'position_name_snapshot'
                        ] ?? ''
                    ) ?>
                </h3>
                <p>
                    <?= e(
                        $currentPosition[
                            'job_title_name_snapshot'
                        ] ?? ''
                    ) ?>
                    &middot;
                    <?= e(
                        $currentPosition[
                            'department_name_snapshot'
                        ] ?? ''
                    ) ?>
                </p>
            </div>
            <dl>
                <div>
                    <dt>Position code</dt>
                    <dd>
                        <?= e(
                            $currentPosition[
                                'position_code_snapshot'
                            ] ?? ''
                        ) ?>
                    </dd>
                </div>
                <div>
                    <dt>Effective from</dt>
                    <dd>
                        <?= e($formatDate(
                            $currentPosition[
                                'effective_from'
                            ] ?? null
                        )) ?>
                    </dd>
                </div>
                <div>
                    <dt>Location</dt>
                    <dd>
                        <?= e(
                            $currentPosition[
                                'branch_name_snapshot'
                            ] ?? 'Company-wide'
                        ) ?>
                    </dd>
                </div>
            </dl>
        </article>
    <?php endif; ?>

    <?php if ($positionHistory !== []): ?>
        <div class="assignment-history-list">
            <?php foreach (
                $positionHistory as $assignment
            ): ?>
                <article class="assignment-history-item">
                    <span class="assignment-history-marker">
                    </span>
                    <div>
                        <div class="assignment-history-heading">
                            <div>
                                <strong>
                                    <?= e(
                                        $assignment[
                                            'position_name_snapshot'
                                        ] ?? ''
                                    ) ?>
                                </strong>
                                <span>
                                    <?= e(
                                        $assignment[
                                            'position_code_snapshot'
                                        ] ?? ''
                                    ) ?>
                                </span>
                            </div>
                            <span class="badge badge-<?= (
                                $assignment[
                                    'assignment_status'
                                ] ?? ''
                            ) === 'current'
                                ? 'success'
                                : 'muted' ?>">
                                <?= (
                                    $assignment[
                                        'assignment_status'
                                    ] ?? ''
                                ) === 'current'
                                    ? 'Current'
                                    : 'Ended' ?>
                            </span>
                        </div>
                        <p>
                            <?= e(
                                $assignment[
                                    'job_title_name_snapshot'
                                ] ?? ''
                            ) ?>
                            &middot;
                            <?= e(
                                $assignment[
                                    'department_name_snapshot'
                                ] ?? ''
                            ) ?>
                        </p>
                        <small>
                            <?= e($formatDate(
                                $assignment[
                                    'effective_from'
                                ] ?? null
                            )) ?>
                            —
                            <?= empty(
                                $assignment['effective_to']
                            )
                                ? 'Present'
                                : e($formatDate(
                                    $assignment[
                                        'effective_to'
                                    ]
                                )) ?>
                            <?php if (!empty(
                                $assignment[
                                    'assigned_by_name'
                                ]
                            )): ?>
                                &middot; Assigned by
                                <?= e(
                                    $assignment[
                                        'assigned_by_name'
                                    ]
                                ) ?>
                            <?php endif; ?>
                        </small>
                        <?php if (!empty(
                            $assignment['notes']
                        )): ?>
                            <p class="assignment-note">
                                <?= e(
                                    $assignment['notes']
                                ) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="card details-section">
    <div class="section-heading">
        <div>
            <h2 class="card-title">
                Direct reports
            </h2>
            <p>
                Employees currently reporting to this
                manager.
            </p>
        </div>
        <span class="count-pill">
            <?= e(count($directReports)) ?>
        </span>
    </div>

    <?php if ($directReports === []): ?>
        <p class="details-empty">
            No direct reports are assigned.
        </p>
    <?php else: ?>
        <div class="direct-report-grid">
            <?php foreach (
                $directReports as $report
            ): ?>
                <a
                    href="/office_app/public/hr/employees/view?id=<?= e(
                        $report['employee_id'] ?? ''
                    ) ?>"
                    class="direct-report-card"
                >
                    <strong>
                        <?= e(
                            $report['displayName'] ?? ''
                        ) ?>
                    </strong>
                    <span>
                        <?= e(
                            $report['job_title'] ?? ''
                        ) ?>
                    </span>
                    <small>
                        <?= e(
                            $report['employee_number']
                            ?? ''
                        ) ?>
                    </small>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
