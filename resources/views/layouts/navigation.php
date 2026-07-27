<?php

declare(strict_types=1);

$currentPath = (string) (
    $data['currentPath'] ?? ''
);
$user = is_array($data['user'] ?? null)
    ? $data['user']
    : [];
$permissions = is_array(
    $user['permissions'] ?? null
)
    ? $user['permissions']
    : [];
$enabledModules = is_array(
    $user['modules'] ?? null
)
    ? $user['modules']
    : [];

$navigation = [
    [
        'label' => 'Dashboard',
        'path' =>
            '/office_app/public/dashboard',
        'icon' => 'DB',
        'permissions' => [
            'dashboard.view',
        ],
    ],
];

foreach ($enabledModules as $module) {
    if (!is_array($module)) {
        continue;
    }

    $routePath = (string) (
        $module['route_path'] ?? ''
    );
    $namespace = (string) (
        $module['permission_namespace'] ?? ''
    );

    if ($routePath === '' || $namespace === '') {
        continue;
    }

    $navigation[] = [
        'label' => (string) (
            $module['navigation_label']
            ?? $module['name']
            ?? ''
        ),
        'path' => '/office_app/public'
            . '/' . ltrim($routePath, '/'),
        'icon' => (string) (
            $module['icon_text'] ?? 'MD'
        ),
        'permission_namespace' => $namespace,
    ];
}

$navigation[] = [
    'label' => !empty(
        $user['is_platform_admin']
    )
        ? 'Vendor Administration'
        : 'Company Administration',
    'path' =>
        '/office_app/public/administration',
    'icon' => 'AD',
    'permissions' => [
        'administration.users.manage',
        'administration.roles.manage',
        'administration.companies.manage',
        'administration.modules.manage',
        'audit.logs.view',
    ],
];

/**
 * @param list<string> $requiredPermissions
 * @param list<string> $userPermissions
 */
function navigationAllowed(
    array $requiredPermissions,
    array $userPermissions
): bool {
    foreach ($requiredPermissions as $permission) {
        if (
            in_array(
                $permission,
                $userPermissions,
                true
            )
        ) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<string> $userPermissions
 */
function moduleNavigationAllowed(
    string $namespace,
    array $userPermissions
): bool {
    $prefix = $namespace . '.';

    foreach ($userPermissions as $permission) {
        if (
            is_string($permission)
            && str_starts_with(
                $permission,
                $prefix
            )
        ) {
            return true;
        }
    }

    return false;
}
?>

<nav aria-label="Primary navigation">
    <div class="navigation-section">
        <span class="navigation-label">
            Workspace
        </span>

        <?php foreach ($navigation as $item): ?>
            <?php
            $allowed = isset(
                $item['permission_namespace']
            )
                ? moduleNavigationAllowed(
                    (string) $item[
                        'permission_namespace'
                    ],
                    $permissions
                )
                : navigationAllowed(
                    $item['permissions'] ?? [],
                    $permissions
                );

            if (!$allowed) {
                continue;
            }

            $isActive =
                $currentPath === $item['path']
                || str_starts_with(
                    $currentPath,
                    $item['path'] . '/'
                );
            ?>

            <a
                href="<?= e($item['path']) ?>"
                class="navigation-link
                    <?= $isActive
                        ? 'is-active'
                        : '' ?>"
            >
                <span
                    class="navigation-icon"
                    aria-hidden="true"
                >
                    <?= e($item['icon']) ?>
                </span>

                <span>
                    <?= e($item['label']) ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
