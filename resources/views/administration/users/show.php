<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];

$profile = is_array($data['profile'] ?? null)
    ? $data['profile']
    : [];
$roles = is_array($data['roles'] ?? null)
    ? $data['roles']
    : [];
$permissions = is_array(
    $data['permissions'] ?? null
)
    ? $data['permissions']
    : [];
$loginAttempts = is_array(
    $data['loginAttempts'] ?? null
)
    ? $data['loginAttempts']
    : [];
$auditActivity = is_array(
    $data['auditActivity'] ?? null
)
    ? $data['auditActivity']
    : [];
$successMessage = is_string(
    $data['successMessage'] ?? null
)
    ? $data['successMessage']
    : '';
$canEdit = !empty($data['canEdit']);
$canResetPassword = !empty(
    $data['canResetPassword']
);
$canChangeStatus = !empty(
    $data['canChangeStatus']
);
$canUnlock = !empty($data['canUnlock']);
$canViewActivity = !empty(
    $data['canViewActivity']
);
$resetCredentials = is_array(
    $data['resetCredentials'] ?? null
)
    ? $data['resetCredentials']
    : null;

$formatDate = static function (mixed $value): string {
    if (!is_string($value) || trim($value) === '') {
        return 'Never';
    }

    $timestamp = strtotime($value);

    return $timestamp === false
        ? $value
        : date('M j, Y g:i A', $timestamp);
};

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

$statusLabel = 'Inactive';
$statusClass = 'badge-muted';

if (!empty($profile['is_locked'])) {
    $statusLabel = 'Locked';
    $statusClass = 'badge-danger';
} elseif (!empty($profile['active'])) {
    $statusLabel = 'Active';
    $statusClass = 'badge-success';
}
?>

<?php if ($successMessage !== ''): ?>
    <div class="alert alert-success" role="status">
        <?= e($successMessage) ?>
    </div>
<?php endif; ?>

<?php if ($resetCredentials !== null): ?>
    <section
        class="alert alert-success credential-alert"
        role="status"
    >
        <strong>Password reset successfully.</strong>

        <p>
            Transfer these credentials securely.
            The temporary password is shown only once.
        </p>

        <dl class="credential-list">
            <div>
                <dt>Username</dt>
                <dd>
                    <?= e(
                        $resetCredentials['username']
                        ?? ''
                    ) ?>
                </dd>
            </div>

            <div>
                <dt>Temporary password</dt>
                <dd>
                    <code>
                        <?= e(
                            $resetCredentials[
                                'temporary_password'
                            ] ?? ''
                        ) ?>
                    </code>
                </dd>
            </div>
        </dl>

        <p class="credential-warning">
            The user must change this password
            at the next sign-in.
        </p>
    </section>
<?php endif; ?>

<div class="details-toolbar">
    <a
        href="/office_app/public/administration/users"
        class="btn btn-secondary"
    >
        Back to users
    </a>

    <div class="details-actions">
        <?php if ($canViewActivity): ?>
            <a
                href="/office_app/public/administration/users/activity?id=<?= e(
                    $profile['user_id'] ?? ''
                ) ?>"
                class="btn btn-secondary"
            >
                View full activity
            </a>
        <?php endif; ?>

        <?php if ($canUnlock): ?>
            <a
                href="/office_app/public/administration/users/unlock?id=<?= e(
                    $profile['user_id'] ?? ''
                ) ?>"
                class="btn btn-secondary"
            >
                Unlock account
            </a>
        <?php endif; ?>

        <?php if ($canChangeStatus): ?>
            <a
                href="/office_app/public/administration/users/account-status?id=<?= e(
                    $profile['user_id'] ?? ''
                ) ?>"
                class="btn <?= !empty(
                    $profile['active']
                )
                    ? 'btn-danger'
                    : 'btn-secondary' ?>"
            >
                <?= !empty($profile['active'])
                    ? 'Deactivate user'
                    : 'Activate user' ?>
            </a>
        <?php endif; ?>

        <?php if ($canResetPassword): ?>
            <a
                href="/office_app/public/administration/users/reset-password?id=<?= e(
                    $profile['user_id'] ?? ''
                ) ?>"
                class="btn btn-secondary"
            >
                Reset password
            </a>
        <?php endif; ?>

        <?php if ($canEdit): ?>
            <a
                href="/office_app/public/administration/users/edit?id=<?= e(
                    $profile['user_id'] ?? ''
                ) ?>"
                class="btn btn-primary"
            >
                Edit user
            </a>
        <?php endif; ?>
    </div>
</div>

<section class="card profile-summary-card">
    <div class="profile-identity">
        <div class="profile-avatar" aria-hidden="true">
            <?= e(strtoupper(substr(
                (string) (
                    $profile['display_name'] ?? 'U'
                ),
                0,
                1
            ))) ?>
        </div>

        <div>
            <h2 class="card-title">
                <?= e(
                    $profile['display_name'] ?? ''
                ) ?>
            </h2>

            <p class="profile-username">
                @<?= e($profile['username'] ?? '') ?>
            </p>
        </div>
    </div>

    <div class="role-badges">
        <?php if (!empty(
            $profile['is_platform_admin']
        )): ?>
            <span class="badge badge-role">
                Platform administrator
            </span>
        <?php endif; ?>

        <span class="badge <?= e($statusClass) ?>">
            <?= e($statusLabel) ?>
        </span>
    </div>
</section>

<div class="details-grid">
    <section class="card details-card">
        <h2 class="card-title">Account information</h2>

        <dl class="metadata-list">
            <div>
                <dt>Email</dt>
                <dd><?= e($profile['email'] ?? '') ?></dd>
            </div>
            <div>
                <dt>User ID</dt>
                <dd>#<?= e($profile['user_id'] ?? '') ?></dd>
            </div>
            <div>
                <dt>Created</dt>
                <dd>
                    <?= e($formatDate(
                        $profile['created_at'] ?? null
                    )) ?>
                </dd>
            </div>
            <div>
                <dt>Last updated</dt>
                <dd>
                    <?= e($formatDate(
                        $profile['updated_at'] ?? null
                    )) ?>
                </dd>
            </div>
        </dl>
    </section>

    <section class="card details-card">
        <h2 class="card-title">Security status</h2>

        <dl class="metadata-list">
            <div>
                <dt>Last login</dt>
                <dd>
                    <?= e($formatDate(
                        $profile['last_login_at'] ?? null
                    )) ?>
                </dd>
            </div>
            <div>
                <dt>Password changed</dt>
                <dd>
                    <?= e($formatDate(
                        $profile['password_changed_at']
                        ?? null
                    )) ?>
                </dd>
            </div>
            <div>
                <dt>Failed login count</dt>
                <dd>
                    <?= e(
                        $profile['failed_login_count']
                        ?? 0
                    ) ?>
                </dd>
            </div>
            <div>
                <dt>Mandatory password change</dt>
                <dd>
                    <?= !empty(
                        $profile['must_change_password']
                    )
                        ? 'Required'
                        : 'Not required' ?>
                </dd>
            </div>
            <div>
                <dt>Locked until</dt>
                <dd>
                    <?= !empty($profile['is_locked'])
                        ? e($formatDate(
                            $profile['locked_until']
                            ?? null
                        ))
                        : 'Not locked' ?>
                </dd>
            </div>
        </dl>
    </section>
</div>

<section class="card details-section">
    <div class="section-heading">
        <div>
            <h2 class="card-title">Assigned roles</h2>
            <p>Roles directly assigned to this account.</p>
        </div>
        <span class="count-pill"><?= e(count($roles)) ?></span>
    </div>

    <?php if ($roles === []): ?>
        <p class="details-empty">No roles are assigned.</p>
    <?php else: ?>
        <div class="role-detail-grid">
            <?php foreach ($roles as $role): ?>
                <article class="role-detail">
                    <strong>
                        <?= e($role['name'] ?? '') ?>
                    </strong>
                    <code><?= e($role['code'] ?? '') ?></code>
                    <p>
                        <?= e(
                            $role['description']
                            ?? 'No description provided.'
                        ) ?>
                    </p>
                    <small>
                        Assigned
                        <?= e($formatDate(
                            $role['assigned_at'] ?? null
                        )) ?>
                        <?php if (!empty(
                            $role['assigned_by_name']
                        )): ?>
                            by
                            <?= e(
                                $role['assigned_by_name']
                            ) ?>
                        <?php endif; ?>
                    </small>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="card details-section">
    <div class="section-heading">
        <div>
            <h2 class="card-title">
                Effective permissions
            </h2>
            <p>
                Active permissions inherited through
                assigned roles.
            </p>
        </div>
        <span class="count-pill">
            <?= e(count($permissions)) ?>
        </span>
    </div>

    <?php if ($permissionGroups === []): ?>
        <p class="details-empty">
            This account has no effective permissions.
        </p>
    <?php else: ?>
        <div class="permission-groups">
            <?php foreach (
                $permissionGroups as $module => $items
            ): ?>
                <section class="permission-group">
                    <h3><?= e(ucwords($module)) ?></h3>

                    <div class="permission-list">
                        <?php foreach ($items as $item): ?>
                            <span
                                class="permission-item"
                                title="<?= e(
                                    $item['description'] ?? ''
                                ) ?>"
                            >
                                <?= e($item['code'] ?? '') ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<div class="activity-grid">
    <section class="card details-section">
        <div class="section-heading">
            <div>
                <h2 class="card-title">
                    Recent login attempts
                </h2>
                <p>Latest 10 authentication events.</p>
            </div>
        </div>

        <?php if ($loginAttempts === []): ?>
            <p class="details-empty">
                No login attempts recorded.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table compact-table">
                    <thead>
                        <tr>
                            <th>Result</th>
                            <th>IP address</th>
                            <th>Reason</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (
                        $loginAttempts as $attempt
                    ): ?>
                        <tr>
                            <td>
                                <span class="badge <?= !empty(
                                    $attempt['successful']
                                )
                                    ? 'badge-success'
                                    : 'badge-danger' ?>">
                                    <?= !empty(
                                        $attempt['successful']
                                    )
                                        ? 'Successful'
                                        : 'Failed' ?>
                                </span>
                            </td>
                            <td>
                                <?= e(
                                    $attempt['ip_address']
                                    ?? ''
                                ) ?>
                            </td>
                            <td>
                                <?= e(
                                    $attempt['failure_reason']
                                    ?? '—'
                                ) ?>
                            </td>
                            <td>
                                <?= e($formatDate(
                                    $attempt['attempted_at']
                                    ?? null
                                )) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card details-section">
        <div class="section-heading">
            <div>
                <h2 class="card-title">
                    Recent audit activity
                </h2>
                <p>
                    Latest 10 actions performed by or
                    targeting this account.
                </p>
            </div>
        </div>

        <?php if ($auditActivity === []): ?>
            <p class="details-empty">
                No audit activity recorded.
            </p>
        <?php else: ?>
            <ol class="activity-list">
                <?php foreach (
                    $auditActivity as $activity
                ): ?>
                    <li>
                        <div>
                            <strong>
                                <?= e(
                                    $activity['action']
                                    ?? 'Activity'
                                ) ?>
                            </strong>
                            <span>
                                <?= e(
                                    $activity['module']
                                    ?? ''
                                ) ?>
                            </span>
                        </div>
                        <p>
                            By
                            <?= e(
                                $activity['actor_name']
                                ?? $activity[
                                    'actor_username'
                                ]
                                ?? 'System'
                            ) ?>
                            from
                            <?= e(
                                $activity['ip_address']
                                ?? 'unknown IP'
                            ) ?>
                        </p>
                        <time>
                            <?= e($formatDate(
                                $activity['created_at']
                                ?? null
                            )) ?>
                        </time>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
</div>
