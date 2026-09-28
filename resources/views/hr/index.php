<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$employees = is_array(
    $data['employees'] ?? null
)
    ? $data['employees']
    : [];
$departments = is_array(
    $data['departments'] ?? null
)
    ? $data['departments']
    : [];
$statusOptions = is_array(
    $data['statusOptions'] ?? null
)
    ? $data['statusOptions']
    : [];
$summary = is_array($data['summary'] ?? null)
    ? $data['summary']
    : [];
$filters = is_array($data['filters'] ?? null)
    ? $data['filters']
    : [];
$pagination = is_array(
    $data['pagination'] ?? null
)
    ? $data['pagination']
    : [];
$canManage = !empty($data['canManage']);
$canViewDirectory = !empty(
    $data['canViewDirectory']
);
$canViewLeave = !empty(
    $data['canViewLeave']
);
$canManageLeavePolicies = !empty(
    $data['canManageLeavePolicies']
);
$canManageLeaveBalances = !empty(
    $data['canManageLeaveBalances']
);
$canViewTeam = !empty(
    $data['canViewTeam']
);
$attendanceEnabled = !empty(
    $data['attendanceEnabled']
);
$canViewAttendance = !empty(
    $data['canViewAttendance']
);
$canViewCompanyAttendance = !empty(
    $data['canViewCompanyAttendance']
);
$canViewOrganization = !empty(
    $data['canViewOrganization']
);
$leaveSummary = is_array(
    $data['leaveSummary'] ?? null
)
    ? $data['leaveSummary']
    : [];
$attendanceSummary = is_array(
    $data['attendanceSummary'] ?? null
)
    ? $data['attendanceSummary']
    : [];
$notice = is_array($data['notice'] ?? null)
    ? $data['notice']
    : null;
$leaveActionCount = (int) (
    $data['actionRequiredCounts']['hr']['leave'] ?? 0
);

$formatDate = static function (mixed $value): string {
    if (!is_string($value) || trim($value) === '') {
        return 'Not recorded';
    }

    $timestamp = strtotime($value);

    return $timestamp === false
        ? $value
        : date('M j, Y', $timestamp);
};

function employeeDirectoryUrl(
    array $filters,
    array $overrides = []
): string {
    $query = array_merge(
        $filters,
        $overrides
    );

    foreach ($query as $key => $value) {
        if (
            $value === ''
            || $value === null
            || $value === 0
        ) {
            unset($query[$key]);
        }
    }

    return '/office_app/public/hr'
        . ($query === []
            ? ''
            : '?' . http_build_query($query));
}

$totalEmployees = array_sum(array_map(
    'intval',
    $summary
));
?>

<?php if ($notice !== null): ?>
    <div class="alert alert-success" role="status">
        <?= e($notice['message'] ?? '') ?>
    </div>
<?php endif; ?>

<section class="hr-command-center">
    <div class="hr-command-copy">
        <span class="section-kicker">
            HR control center
        </span>
        <h2>
            Run the employee lifecycle from one workspace.
        </h2>
        <p>
            Keep workforce records, organization structure,
            leave decisions and daily attendance connected
            to the active company.
        </p>
    </div>

    <div class="hr-command-pulse">
        <article>
            <span>Leave approvals waiting</span>
            <strong>
                <?= e(
                    $leaveSummary['pending'] ?? 0
                ) ?>
            </strong>
        </article>
        <article>
            <span>On leave today</span>
            <strong>
                <?= e(
                    $leaveSummary[
                        'onLeaveToday'
                    ] ?? 0
                ) ?>
            </strong>
        </article>
        <article>
            <?php if (
                $canViewCompanyAttendance
            ): ?>
                <span>Attendance recorded today</span>
                <strong>
                    <?= e(
                        $attendanceSummary[
                            'recorded'
                        ] ?? 0
                    ) ?>
                    <small>
                        / <?= e(
                            $attendanceSummary[
                                'total'
                            ] ?? 0
                        ) ?>
                    </small>
                </strong>
            <?php elseif ($canViewAttendance): ?>
                <span>Attendance self service</span>
                <strong>
                    Available
                </strong>
            <?php else: ?>
                <span>Attendance module</span>
                <strong>
                    <?= $attendanceEnabled
                        ? 'Restricted'
                        : 'Off' ?>
                </strong>
            <?php endif; ?>
        </article>
    </div>
</section>

<section
    class="hr-workspace-grid"
    aria-label="Human Resources workspaces"
>
    <?php if ($canViewDirectory): ?>
    <article class="card hr-workspace-card">
        <div class="hr-workspace-icon" aria-hidden="true">
            PE
        </div>
        <div>
            <span class="workspace-status is-live">
                Core HR
            </span>
            <h3>People directory</h3>
            <p>
                Employee records, reporting lines,
                employment details and account links.
            </p>
        </div>
        <a
            href="#employee-directory"
            class="workspace-link"
        >
            Open directory
        </a>
    </article>
    <?php endif; ?>

    <?php if ($canViewTeam): ?>
    <article class="card hr-workspace-card">
        <div class="hr-workspace-icon" aria-hidden="true">
            TM
        </div>
        <div>
            <span class="workspace-status is-live">
                Employee workspace
            </span>
            <h3>My team</h3>
            <p>
                Personal leave balances, reporting lines,
                direct reports and manager approvals.
            </p>
        </div>
        <a
            href="/office_app/public/hr/team"
            class="workspace-link"
        >
            Open my team
        </a>
    </article>
    <?php endif; ?>

    <article class="card hr-workspace-card">
        <div class="hr-workspace-icon" aria-hidden="true">
            LV
        </div>
        <div>
            <span class="workspace-status is-live">
                HR operations
            </span>
            <h3>Leave management</h3>
            <p>
                Submit leave, review working-day totals
                and control approval decisions.
            </p>
        </div>
        <?php if ($canViewLeave): ?>
            <div class="workspace-card-actions">
                <a
                    href="/office_app/public/hr/leave"
                    class="workspace-link workflow-action-link<?= $leaveActionCount > 0 ? ' has-action-badge' : '' ?>"
                >
                    Manage leave
                    <?php if ($leaveActionCount > 0): ?>
                        <span
                            class="nav-action-badge"
                            aria-label="<?= e($leaveActionCount . ' ' . ($leaveActionCount === 1 ? 'action' : 'actions') . ' required') ?>"
                        ><?= e($leaveActionCount) ?></span>
                    <?php endif; ?>
                </a>
                <?php if ($canManageLeavePolicies): ?>
                    <a
                        href="/office_app/public/hr/leave/policies"
                        class="workspace-link is-secondary"
                    >
                        Configure policies
                    </a>
                <?php endif; ?>
                <?php if ($canManageLeaveBalances): ?>
                    <a
                        href="/office_app/public/hr/leave/balances"
                        class="workspace-link is-secondary"
                    >
                        Manage balances
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <span class="workspace-link is-disabled">
                Permission required
            </span>
        <?php endif; ?>
    </article>

    <article class="card hr-workspace-card">
        <div class="hr-workspace-icon" aria-hidden="true">
            AT
        </div>
        <div>
            <span class="workspace-status <?= $attendanceEnabled
                ? 'is-live'
                : 'is-optional' ?>">
                <?= $attendanceEnabled
                    ? 'Licensed module'
                    : 'Optional module' ?>
            </span>
            <h3>Attendance control</h3>
            <p>
                Daily presence, late arrivals, remote
                work and working-time exceptions.
            </p>
        </div>
        <?php if ($canViewAttendance): ?>
            <a
                href="/office_app/public/attendance"
                class="workspace-link"
            >
                Open attendance
            </a>
        <?php elseif (!$attendanceEnabled): ?>
            <span class="workspace-link is-disabled">
                License not enabled
            </span>
        <?php else: ?>
            <span class="workspace-link is-disabled">
                Permission required
            </span>
        <?php endif; ?>
    </article>

    <?php if ($canViewOrganization): ?>
    <article class="card hr-workspace-card">
        <div class="hr-workspace-icon" aria-hidden="true">
            OR
        </div>
        <div>
            <span class="workspace-status is-live">
                Organization
            </span>
            <h3>Workforce structure</h3>
            <p>
                Departments, branches, job titles,
                approved positions and assignments.
            </p>
        </div>
        <?php if ($canViewOrganization): ?>
            <a
                href="/office_app/public/organization/setup"
                class="workspace-link"
            >
                Open setup center
            </a>
        <?php else: ?>
            <span class="workspace-link is-disabled">
                Permission required
            </span>
        <?php endif; ?>
    </article>
    <?php endif; ?>
</section>

<?php if ($canManage): ?>
    <div class="hr-action-bar">
        <a
            href="/office_app/public/hr/departments"
            class="btn btn-secondary"
        >
            Manage departments
        </a>
        <a
            href="/office_app/public/hr/employees/create"
            class="btn btn-primary"
        >
            Create employee
        </a>
    </div>
<?php endif; ?>

<?php if ($canViewDirectory): ?>
<section class="hr-summary-grid">
    <article class="card hr-summary-card">
        <span>Total employees</span>
        <strong><?= e($totalEmployees) ?></strong>
    </article>
    <article class="card hr-summary-card">
        <span>Active</span>
        <strong>
            <?= e($summary['active'] ?? 0) ?>
        </strong>
    </article>
    <article class="card hr-summary-card">
        <span>On leave</span>
        <strong>
            <?= e($summary['on_leave'] ?? 0) ?>
        </strong>
    </article>
    <article class="card hr-summary-card">
        <span>Terminated</span>
        <strong>
            <?= e($summary['terminated'] ?? 0) ?>
        </strong>
    </article>
</section>

<section
    class="card hr-filter-panel"
    id="employee-directory"
>
    <?php view('components.list-filters', [
        'query' => $data['listQuery'], 'path' => '/office_app/public/hr',
        'sorts' => ['name'=>'Name','number'=>'Employee number','department'=>'Department','status'=>'Status','hire_date'=>'Hire date'],
        'filters' => [
            'status'=>['label'=>'Status','options'=>$statusOptions],
            'department'=>['label'=>'Department','options'=>$data['listOptions']['department']],
            'branch'=>['label'=>'Branch','options'=>$data['listOptions']['branch']],
        ],
    ]); ?>
    <div class="filter-actions">
        <?php if ($canManage): ?><a class="btn btn-secondary" href="/office_app/public/data-exchange/employees/import">Import employees</a><?php endif; ?>
        <a class="btn btn-secondary" href="<?= e($data['listQuery']->url('/office_app/public/data-exchange/employees/export', ['format'=>'xlsx'])) ?>">Export filtered</a>
        <a class="btn btn-secondary" href="/office_app/public/data-exchange/employees/export?format=xlsx">Export all authorized</a>
    </div>
</section>

<section class="card table-card">
    <div class="table-summary">
        <strong>
            <?= e($pagination['total'] ?? 0) ?>
            employees
        </strong>
        <span>
            Showing
            <?= e($pagination['from'] ?? 0) ?>
            &ndash;
            <?= e($pagination['to'] ?? 0) ?>
        </span>
    </div>

    <div class="table-responsive">
        <table class="data-table employee-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Job title</th>
                    <th>Department</th>
                    <th>Employment</th>
                    <th>Contact</th>
                    <th>Status</th>
                    <th class="table-actions-column">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody>
            <?php if ($employees === []): ?>
                <tr>
                    <td
                        colspan="7"
                        class="empty-state"
                    >
                        No employees matched the filters.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach (
                    $employees as $employee
                ): ?>
                    <tr>
                        <td>
                            <strong>
                                <?= e(
                                    $employee['displayName']
                                    ?? ''
                                ) ?>
                            </strong>
                            <small>
                                <?= e(
                                    $employee[
                                        'employee_number'
                                    ] ?? ''
                                ) ?>
                            </small>
                        </td>
                        <td>
                            <?= e(
                                $employee['job_title']
                                ?? ''
                            ) ?>
                        </td>
                        <td>
                            <?= e(
                                $employee['department_name']
                                ?? 'Unassigned'
                            ) ?>
                        </td>
                        <td>
                            <strong>
                                <?= e(
                                    $employee['typeLabel']
                                    ?? ''
                                ) ?>
                            </strong>
                            <small>
                                Since
                                <?= e($formatDate(
                                    $employee['hire_date']
                                    ?? null
                                )) ?>
                            </small>
                        </td>
                        <td>
                            <?= e(
                                $employee['work_email']
                                ?? ''
                            ) ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= e(
                                $employee['statusTone']
                                ?? 'muted'
                            ) ?>">
                                <?= e(
                                    $employee['statusLabel']
                                    ?? ''
                                ) ?>
                            </span>
                        </td>
                        <td>
                            <a
                                href="/office_app/public/hr/employees/view?id=<?= e(
                                    $employee['employee_id']
                                    ?? ''
                                ) ?>"
                                class="table-link"
                            >
                                View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php view('components.list-pagination', ['query'=>$data['listQuery'], 'pagination'=>$pagination, 'path'=>'/office_app/public/hr']); ?>
</section>
<?php endif; ?>
