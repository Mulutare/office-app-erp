<?php

declare(strict_types=1);

$modules = [
    [
        'title' => 'Users',
        'description' =>
            'Create, update, disable and review user accounts.',
        'path' =>
            '/office_app/public/administration/users',
        'permission' =>
            'administration.users.manage',
    ],
    [
        'title' => 'Roles and permissions',
        'description' =>
            'Control access to ERP modules and operations.',
        'path' =>
            '/office_app/public/administration/roles',
        'permission' =>
            'administration.roles.manage',
    ],
    [
        'title' => 'Company modules',
        'description' =>
            'Enable licensed ERP modules for this company.',
        'path' =>
            '/office_app/public/administration/modules',
        'permission' =>
            'administration.modules.manage',
    ],
    [
        'title' => 'Audit logs',
        'description' =>
            'Review security and business activity records.',
        'path' =>
            '/office_app/public/administration/audit-logs',
        'permission' =>
            'audit.logs.view',
    ],
];

$permissions = is_array(
    $data['user']['permissions'] ?? null
)
    ? $data['user']['permissions']
    : [];
?>

<section class="module-grid">
    <?php foreach ($modules as $module): ?>
        <?php
        if (
            !in_array(
                $module['permission'],
                $permissions,
                true
            )
        ) {
            continue;
        }
        ?>

        <article class="card module-card">
            <h2 class="card-title">
                <?= e($module['title']) ?>
            </h2>

            <p class="module-description">
                <?= e($module['description']) ?>
            </p>

            <a
                href="<?= e($module['path']) ?>"
                class="btn btn-primary"
            >
                Open
            </a>
        </article>
    <?php endforeach; ?>
</section>
