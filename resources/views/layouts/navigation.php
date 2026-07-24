<?php

declare(strict_types=1);

$currentPath = (string) (
    $data['currentPath'] ?? ''
);

$navigation = [
    [
        'label' => 'Dashboard',
        'path' => '/office_app/public/dashboard',
        'icon' => '⌂',
    ],
    [
        'label' => 'Human Resources',
        'path' => '/office_app/public/hr',
        'icon' => '◉',
    ],
    [
        'label' => 'Finance',
        'path' => '/office_app/public/finance',
        'icon' => '¤',
    ],
    [
        'label' => 'IT Management',
        'path' => '/office_app/public/it',
        'icon' => '⚙',
    ],
    [
        'label' => 'Business Development',
        'path' => '/office_app/public/business',
        'icon' => '◆',
    ],
    [
        'label' => 'Reports',
        'path' => '/office_app/public/reports',
        'icon' => '▤',
    ],
    [
        'label' => 'Administration',
        'path' => '/office_app/public/administration',
        'icon' => '♙',
    ],
];
?>

<nav aria-label="Primary navigation">
    <div class="navigation-section">
        <span class="navigation-label">
            Workspace
        </span>

        <?php foreach ($navigation as $item): ?>
            <?php
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