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

$navigation = [
    [
        'label' => 'Dashboard',
        'path' =>
            '/office_app/public/dashboard',
        'icon' => '⌂',
        'permissions' => [
            'dashboard.view',
        ],
    ],
    [
        'label' => 'Human Resources',
        'path' =>
            '/office_app/public/hr',
        'icon' => '◉',
        'permissions' => [
            'hr.records.view',
            'hr.records.manage',
        ],
    ],
    [
        'label' => 'Finance',
        'path' =>
            '/office_app/public/finance',
        'icon' => '¤',
        'permissions' => [
            'finance.records.view',
            'finance.records.manage',
            'finance.requests.approve',
        ],
    ],
    [
        'label' => 'IT Management',
        'path' =>
            '/office_app/public/it',
        'icon' => '⚙',
        'permissions' => [
            'it.records.view',
            'it.records.manage',
        ],
    ],
    [
        'label' => 'Business Development',
        'path' =>
            '/office_app/public/business',
        'icon' => '◆',
        'permissions' => [
            'business.records.view',
            'business.records.manage',
        ],
    ],
    [
        'label' => 'Administration',
        'path' =>
            '/office_app/public/administration',
        'icon' => '♙',
        'permissions' => [
            'administration.users.manage',
            'administration.roles.manage',
            'audit.logs.view',
        ],
    ],
];

/**
 * Determine whether the user has any required permission.
 *
 * @param list<string> $requiredPermissions
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
?>

<nav aria-label="Primary navigation">
    <div class="navigation-section">
        <span class="navigation-label">
            Workspace
        </span>

        <?php foreach ($navigation as $item): ?>
            <?php
            if (
                !navigationAllowed(
                    $item['permissions'],
                    $permissions
                )
            ) {
                continue;
            }

            $isActive =
                $currentPath === $item['path'];
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