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
        'title' => 'Customer companies',
        'description' =>
            'Provision customer workspaces and their initial ERP subscriptions.',
        'path' =>
            '/office_app/public/administration/companies',
        'permission' =>
            'administration.companies.manage',
        'platformOnly' => true,
    ],
    [
        'title' => 'Company modules',
        'description' =>
            'Enable licensed modules for the configured workspace.',
        'path' =>
            '/office_app/public/administration/modules',
        'permission' =>
            'administration.modules.manage',
        'platformOnly' => true,
    ],
    [
        'title' => 'Company branches',
        'description' =>
            'Maintain company locations, head office details and operational availability.',
        'path' =>
            '/office_app/public/organization/branches',
        'permission' =>
            'organization.branches.view',
        'tenantOnly' => true,
    ],
    [
        'title' => 'Job title catalogue',
        'description' =>
            'Standardize job titles, job families and grade references for workforce planning.',
        'path' =>
            '/office_app/public/organization/job-titles',
        'permission' =>
            'organization.job_titles.view',
        'tenantOnly' => true,
    ],
    [
        'title' => 'Department catalogue',
        'description' =>
            'Maintain department hierarchy and review tenant-isolated workforce assignments.',
        'path' =>
            '/office_app/public/organization/departments',
        'permission' =>
            'organization.departments.view',
        'tenantOnly' => true,
    ],
    [
        'title' => 'Position catalogue',
        'description' =>
            'Plan approved positions across departments, job titles and company locations.',
        'path' =>
            '/office_app/public/organization/positions',
        'permission' =>
            'organization.positions.view',
        'tenantOnly' => true,
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
$isPlatformAdmin = !empty(
    $data['user']['is_platform_admin']
);
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

        if (
            !empty($module['platformOnly'])
            && !$isPlatformAdmin
        ) {
            continue;
        }

        if (
            !empty($module['tenantOnly'])
            && $isPlatformAdmin
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
