<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$departments = is_array(
    $data['departments'] ?? null
)
    ? $data['departments']
    : [];
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
    <a
        href="/office_app/public/hr/departments/create"
        class="btn btn-primary"
    >
        Create department
    </a>
</div>

<section class="card table-card">
    <div class="table-summary">
        <div>
            <strong>
                <?= e(count($departments)) ?>
                departments
            </strong>
            <span class="table-summary-note">
                Current assignments exclude terminated
                employees.
            </span>
        </div>
    </div>

    <div class="table-responsive">
        <table
            class="data-table department-table"
        >
            <thead>
                <tr>
                    <th>Department</th>
                    <th>Description</th>
                    <th>Employees</th>
                    <th>Status</th>
                    <th>Updated</th>
                    <th class="table-actions-column">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody>
            <?php if ($departments === []): ?>
                <tr>
                    <td
                        colspan="6"
                        class="empty-state"
                    >
                        No departments have been created.
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
                                    $department['name']
                                    ?? ''
                                ) ?>
                            </strong>
                            <small>
                                <?= e(
                                    $department['code']
                                    ?? ''
                                ) ?>
                            </small>
                        </td>
                        <td class="department-description">
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
                                <?= !empty(
                                    $department['active']
                                )
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
                        <td>
                            <a
                                href="/office_app/public/hr/departments/edit?id=<?= e(
                                    $department[
                                        'department_id'
                                    ] ?? 0
                                ) ?>"
                                class="table-link"
                            >
                                Edit
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
