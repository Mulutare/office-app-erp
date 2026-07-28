<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$departments = is_array(
    $data['departments'] ?? null
)
    ? $data['departments']
    : [];
$summary = is_array($data['summary'] ?? null)
    ? $data['summary']
    : [];
$notice = is_array($data['notice'] ?? null)
    ? $data['notice']
    : null;
$canManage = !empty($data['canManage']);

$formatDate = static function (mixed $value): string {
    if (!is_string($value) || trim($value) === '') {
        return 'Not recorded';
    }

    $timestamp = strtotime($value);

    return $timestamp === false
        ? $value
        : date('M j, Y', $timestamp);
};
?>

<?php if ($notice !== null): ?>
    <div class="alert alert-success" role="status">
        <?= e($notice['message'] ?? '') ?>
    </div>
<?php endif; ?>

<section class="department-overview">
    <div class="department-overview-heading">
        <div>
            <span class="eyebrow">Organization</span>
            <h2>Operating structure</h2>
            <p>
                A shared department hierarchy for people,
                finance, projects, approvals and reporting.
            </p>
        </div>

        <?php if ($canManage): ?>
            <a
                href="/office_app/public/organization/departments/create"
                class="btn btn-primary"
            >
                Create department
            </a>
        <?php endif; ?>
    </div>

    <div
        class="department-metrics"
        aria-label="Department summary"
    >
        <article class="card">
            <span>Total departments</span>
            <strong><?= e((int) (
                $summary['total'] ?? 0
            )) ?></strong>
        </article>
        <article class="card">
            <span>Active departments</span>
            <strong><?= e((int) (
                $summary['active'] ?? 0
            )) ?></strong>
        </article>
        <article class="card">
            <span>Top-level units</span>
            <strong><?= e((int) (
                $summary['topLevel'] ?? 0
            )) ?></strong>
        </article>
        <article class="card">
            <span>Current employees</span>
            <strong><?= e((int) (
                $summary['currentEmployees'] ?? 0
            )) ?></strong>
        </article>
    </div>
</section>

<section class="card table-card">
    <div class="table-summary">
        <div>
            <strong>
                <?= e(count($departments)) ?>
                registered departments
            </strong>
            <span class="table-summary-note">
                Results and employee counts are restricted to
                the current company.
            </span>
        </div>
    </div>

    <div class="table-responsive">
        <table class="data-table organization-department-table">
            <thead>
                <tr>
                    <th>Department</th>
                    <th>Reports to</th>
                    <th>Description</th>
                    <th>Workforce</th>
                    <th>Status</th>
                    <th>Updated</th>
                    <?php if ($canManage): ?>
                        <th class="table-actions-column">
                            Actions
                        </th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if ($departments === []): ?>
                <tr>
                    <td
                        colspan="<?= $canManage ? '7' : '6' ?>"
                        class="empty-state"
                    >
                        No departments have been created for
                        this company.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach (
                    $departments as $department
                ): ?>
                    <tr>
                        <td>
                            <strong>
                                <?= e(
                                    $department['name'] ?? ''
                                ) ?>
                            </strong>
                            <small>
                                <?= e(
                                    $department['code'] ?? ''
                                ) ?>
                            </small>
                        </td>
                        <td>
                            <?php if (!empty(
                                $department[
                                    'parent_department_name'
                                ]
                            )): ?>
                                <span class="hierarchy-reference">
                                    <?= e(
                                        $department[
                                            'parent_department_name'
                                        ]
                                    ) ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-role">
                                    Top level
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="department-catalogue-description">
                            <?= e(
                                $department['description']
                                ?? 'No description'
                            ) ?>
                        </td>
                        <td>
                            <strong>
                                <?= e((int) (
                                    $department[
                                        'current_employee_count'
                                    ] ?? 0
                                )) ?>
                                current
                            </strong>
                            <small>
                                <?= e((int) (
                                    $department[
                                        'employee_count'
                                    ] ?? 0
                                )) ?>
                                total records
                            </small>
                        </td>
                        <td>
                            <span class="badge <?= !empty(
                                $department['active']
                            )
                                ? 'badge-success'
                                : 'badge-muted' ?>">
                                <?= !empty($department['active'])
                                    ? 'Active'
                                    : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <?= e($formatDate(
                                $department['updated_at']
                                    ?? null
                            )) ?>
                        </td>
                        <?php if ($canManage): ?>
                            <td>
                                <a
                                    href="/office_app/public/organization/departments/edit?id=<?= e(
                                        $department[
                                            'department_id'
                                        ] ?? 0
                                    ) ?>"
                                    class="table-link"
                                >
                                    Edit
                                </a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
