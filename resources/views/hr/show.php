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
