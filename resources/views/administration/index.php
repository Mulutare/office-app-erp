<?php

declare(strict_types=1);

$modules = [
    [
        'title' => 'Analytics / Power BI',
        'description' =>
            'Configure this company’s licensed Power BI report and authentication mode.',
        'path' =>
            appBasePath() . '/administration/analytics',
        'permission' =>
            'analytics.configure',
        'tenantOnly' => true,
    ],
    [
        'title' => 'Users',
        'description' =>
            'Create, update, disable and review user accounts.',
        'path' =>
            appBasePath() . '/administration/users',
        'permission' =>
            'administration.users.manage',
    ],
    [
        'title' => 'Roles and permissions',
        'description' =>
            'Control access to ERP modules and operations.',
        'path' =>
            appBasePath() . '/administration/roles',
        'permission' =>
            'administration.roles.manage',
    ],
    [
        'title' => 'Customer companies',
        'description' =>
            'Provision customer workspaces and their initial ERP subscriptions.',
        'path' =>
            appBasePath() . '/administration/companies',
        'permission' =>
            'administration.companies.manage',
        'platformOnly' => true,
    ],
    [
        'title' => 'Company modules',
        'description' =>
            'Manage module availability for configured customer workspaces.',
        'path' =>
            appBasePath() . '/administration/modules',
        'permission' =>
            'administration.modules.manage',
        'platformOnly' => true,
    ],
    [
        'title' => 'Organization setup center',
        'description' =>
            'Measure company readiness and complete locations, departments, job architecture, headcount and reporting lines in sequence.',
        'path' =>
            appBasePath() . '/organization/setup',
        'permissions' => [
            'organization.branches.view',
            'organization.branches.manage',
            'organization.job_titles.view',
            'organization.job_titles.manage',
            'organization.departments.view',
            'organization.departments.manage',
            'organization.positions.view',
            'organization.positions.manage',
            'hr.records.view',
            'hr.records.manage',
        ],
        'tenantOnly' => true,
    ],
    [
        'title' => 'Company branches',
        'description' =>
            'Maintain company locations, head office details and operational availability.',
        'path' =>
            appBasePath() . '/organization/branches',
        'permission' =>
            'organization.branches.view',
        'tenantOnly' => true,
    ],
    [
        'title' => 'Job title catalogue',
        'description' =>
            'Standardize job titles, job families and grade references for workforce planning.',
        'path' =>
            appBasePath() . '/organization/job-titles',
        'permission' =>
            'organization.job_titles.view',
        'tenantOnly' => true,
    ],
    [
        'title' => 'Department catalogue',
        'description' =>
            'Maintain department hierarchy and review tenant-isolated workforce assignments.',
        'path' =>
            appBasePath() . '/organization/departments',
        'permission' =>
            'organization.departments.view',
        'tenantOnly' => true,
    ],
    [
        'title' => 'Position catalogue',
        'description' =>
            'Plan approved positions across departments, job titles and company locations.',
        'path' =>
            appBasePath() . '/organization/positions',
        'permission' =>
            'organization.positions.view',
        'tenantOnly' => true,
    ],
    [
        'title' => 'Integration events',
        'description' =>
            'Review failed company integration events and safely requeue authorized failures.',
        'path' =>
            appBasePath() . '/administration/integration-events',
        'permission' =>
            'administration.integration_events.view',
        'tenantOnly' => true,
        'actionCountKey' => 'integration_events',
    ],
    [
        'title' => 'Audit logs',
        'description' =>
            'Review security and business activity records.',
        'path' =>
            appBasePath() . '/administration/audit-logs',
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
            isset($module['permissions'])
                ? array_intersect(
                    $module['permissions'],
                    $permissions
                ) === []
                : !in_array(
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

            <?php
            $actionCount = isset($module['actionCountKey'])
                ? (int) ($data['actionRequiredCounts']['administration'][$module['actionCountKey']] ?? 0)
                : 0;
            ?>
            <a
                href="<?= e($module['path']) ?>"
                class="btn btn-primary<?= isset($module['actionCountKey']) ? ' workflow-action-link' : '' ?><?= $actionCount > 0 ? ' has-action-badge' : '' ?>"
            >
                Open
                <?php if ($actionCount > 0): ?>
                    <span
                        class="nav-action-badge"
                        aria-label="<?= e($actionCount . ' ' . ($actionCount === 1 ? 'action' : 'actions') . ' required') ?>"
                    ><?= e($actionCount) ?></span>
                <?php endif; ?>
            </a>
        </article>
    <?php endforeach; ?>
</section>
