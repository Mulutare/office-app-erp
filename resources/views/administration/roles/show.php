<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$role = is_array($data['role'] ?? null)
    ? $data['role']
    : [];
$permissions = is_array(
    $data['permissions'] ?? null
)
    ? $data['permissions']
    : [];
$assignedUsers = is_array(
    $data['assignedUsers'] ?? null
)
    ? $data['assignedUsers']
    : [];
$canEditPermissions = !empty(
    $data['canEditPermissions']
);
$successMessage = is_string(
    $data['successMessage'] ?? null
)
    ? $data['successMessage']
    : '';

$permissionGroups = [];

foreach ($permissions as $permission) {
    if (!is_array($permission)) {
        continue;
    }

    $module = (string) (
        $permission['module'] ?? 'Other'
    );
    $permissionGroups[$module][] = $permission;
}
?>

<?php if ($successMessage !== ''): ?>
    <div class="alert alert-success" role="status">
        <?= e($successMessage) ?>
    </div>
<?php endif; ?>

<div class="details-toolbar">
    <a
        href="<?= e(appBasePath()) ?>/administration/roles"
        class="btn btn-secondary"
    >
        Back to roles
    </a>

    <?php if ($canEditPermissions): ?>
        <a
            href="<?= e(appBasePath()) ?>/administration/roles/edit-permissions?id=<?= e(
                $role['role_id'] ?? ''
            ) ?>"
            class="btn btn-primary"
        >
            Edit permissions
        </a>
    <?php endif; ?>
</div>

<section class="card profile-summary-card">
    <div>
        <div class="role-title-row">
            <h2 class="card-title">
                <?= e($role['name'] ?? '') ?>
            </h2>

            <?php if (!empty($role['is_system'])): ?>
                <span class="badge badge-role">
                    System role
                </span>
            <?php endif; ?>
        </div>

        <code><?= e($role['code'] ?? '') ?></code>

        <p class="profile-username">
            <?= e(
                $role['description']
                ?? 'No description provided.'
            ) ?>
        </p>
    </div>

    <span class="badge <?= !empty($role['active'])
        ? 'badge-success'
        : 'badge-muted' ?>">
        <?= !empty($role['active'])
            ? 'Active'
            : 'Inactive' ?>
    </span>
</section>

<section class="card details-section">
    <div class="section-heading">
        <div>
            <h2 class="card-title">
                Effective permissions
            </h2>
            <p>
                Permissions granted directly to this role.
            </p>
        </div>
        <span class="count-pill">
            <?= e(count($permissions)) ?>
        </span>
    </div>

    <?php if ($permissionGroups === []): ?>
        <p class="details-empty">
            No permissions are assigned to this role.
        </p>
    <?php else: ?>
        <div class="permission-groups">
            <?php foreach (
                $permissionGroups as $module => $items
            ): ?>
                <section class="permission-group">
                    <h3><?= e(ucwords($module)) ?></h3>

                    <div class="role-permission-list">
                        <?php foreach ($items as $item): ?>
                            <article>
                                <strong>
                                    <?= e(
                                        $item['name'] ?? ''
                                    ) ?>
                                </strong>
                                <code>
                                    <?= e(
                                        $item['code'] ?? ''
                                    ) ?>
                                </code>
                                <p>
                                    <?= e(
                                        $item['description']
                                        ?? ''
                                    ) ?>
                                </p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="card table-card">
    <div class="table-summary">
        <div>
            <strong>Assigned users</strong>
            <span>
                First <?= e(count($assignedUsers)) ?>
                active or inactive accounts
            </span>
        </div>
        <span class="count-pill">
            <?= e(count($assignedUsers)) ?>
        </span>
    </div>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Assigned</th>
                    <th>Assigned by</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($assignedUsers === []): ?>
                <tr>
                    <td colspan="6" class="empty-state">
                        No users are assigned to this role.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach (
                    $assignedUsers as $assignedUser
                ): ?>
                    <tr>
                        <td>
                            <strong>
                                <?= e(
                                    $assignedUser[
                                        'display_name'
                                    ] ?? ''
                                ) ?>
                            </strong>
                            <small>
                                @<?= e(
                                    $assignedUser[
                                        'username'
                                    ] ?? ''
                                ) ?>
                            </small>
                        </td>
                        <td>
                            <?= e(
                                $assignedUser['email'] ?? ''
                            ) ?>
                        </td>
                        <td>
                            <span class="badge <?= !empty(
                                $assignedUser['active']
                            )
                                ? 'badge-success'
                                : 'badge-muted' ?>">
                                <?= !empty(
                                    $assignedUser['active']
                                )
                                    ? 'Active'
                                    : 'Inactive' ?>
                            </span>
                        </td>
                        <td>
                            <?= e(
                                $assignedUser[
                                    'assigned_at'
                                ] ?? ''
                            ) ?>
                        </td>
                        <td>
                            <?= e(
                                $assignedUser[
                                    'assigned_by_name'
                                ] ?? 'System'
                            ) ?>
                        </td>
                        <td>
                            <a
                                href="<?= e(appBasePath()) ?>/administration/users/view?id=<?= e(
                                    $assignedUser[
                                        'user_id'
                                    ] ?? ''
                                ) ?>"
                                class="table-link"
                            >
                                View user
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
