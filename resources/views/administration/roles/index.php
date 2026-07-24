<?php

declare(strict_types=1);

/** @var array<string, mixed> $data */
$data = is_array($data ?? null) ? $data : [];
$roles = is_array($data['roles'] ?? null)
    ? $data['roles']
    : [];
?>

<div class="details-toolbar">
    <a
        href="/office_app/public/administration"
        class="btn btn-secondary"
    >
        Back to administration
    </a>
</div>

<section class="role-management-grid">
    <?php foreach ($roles as $role): ?>
        <article class="card role-management-card">
            <div class="section-heading">
                <div>
                    <h2 class="card-title">
                        <?= e($role['name'] ?? '') ?>
                    </h2>
                    <code>
                        <?= e($role['code'] ?? '') ?>
                    </code>
                </div>

                <span class="badge <?= !empty(
                    $role['active']
                )
                    ? 'badge-success'
                    : 'badge-muted' ?>">
                    <?= !empty($role['active'])
                        ? 'Active'
                        : 'Inactive' ?>
                </span>
            </div>

            <p class="role-management-description">
                <?= e(
                    $role['description']
                    ?? 'No description provided.'
                ) ?>
            </p>

            <dl class="role-statistics">
                <div>
                    <dt>Permissions</dt>
                    <dd>
                        <?= e(
                            $role['permission_count'] ?? 0
                        ) ?>
                    </dd>
                </div>
                <div>
                    <dt>Assigned users</dt>
                    <dd>
                        <?= e($role['user_count'] ?? 0) ?>
                    </dd>
                </div>
                <div>
                    <dt>Active users</dt>
                    <dd>
                        <?= e(
                            $role['active_user_count']
                            ?? 0
                        ) ?>
                    </dd>
                </div>
            </dl>

            <a
                href="/office_app/public/administration/roles/view?id=<?= e(
                    $role['role_id'] ?? ''
                ) ?>"
                class="btn btn-primary"
            >
                View role
            </a>
        </article>
    <?php endforeach; ?>
</section>
