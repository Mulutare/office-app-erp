<?php

declare(strict_types=1);
/** @var array<string, mixed> $data */
$data = is_array($data ?? null)
    ? $data
    : [];

$pageTitle = (string) (
    $data['pageTitle'] ?? 'Dashboard'
);

$pageDescription = (string) (
    $data['pageDescription'] ?? ''
);

$contentView = (string) (
    $data['contentView'] ?? ''
);

$user = is_array($data['user'] ?? null)
    ? $data['user']
    : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta
        name="robots"
        content="noindex, nofollow"
    >

    <title>
        <?= e($pageTitle) ?>
        |
        <?= e(
            $data['applicationName']
            ?? 'OfficeApp ERP'
        ) ?>
    </title>

    <link
        rel="stylesheet"
        href="/office_app/public/assets/css/app.css"
    >
</head>

<body>
<div class="app-shell">
    <header class="app-header">
        <div class="brand">
            <button
                type="button"
                class="mobile-menu-button"
                data-sidebar-toggle
                aria-label="Open navigation"
                aria-expanded="false"
            >
                ☰
            </button>

            <img
                class="brand-logo"
                src="/office_app/public/assets/images/company-logo.png"
                alt=""
                onerror="this.style.display='none'"
            >

            <div>
                <p class="brand-title">
                    <?= e(
                        $data['applicationName']
                        ?? 'OfficeApp ERP'
                    ) ?>
                </p>

                <p class="brand-subtitle">
                    Enterprise Management System
                </p>
            </div>
        </div>

        <div class="header-actions">
            <div class="user-summary">
                <strong>
                    <?= e(
                        $user['display_name']
                        ?? 'User'
                    ) ?>
                </strong>

                <span>
                    <?= e(
                        $user['username']
                        ?? ''
                    ) ?>
                </span>
            </div>

            <form
                method="post"
                action="/office_app/public/logout"
            >
                <?= csrfField() ?>

                <button
                    type="submit"
                    class="btn btn-danger"
                >
                    Sign out
                </button>
            </form>
        </div>
    </header>

    <aside
        class="app-sidebar"
        data-sidebar
    >
        <?php
        \view('layouts.navigation', [
            'currentPath' =>
                parse_url(
                    $_SERVER['REQUEST_URI'] ?? '/',
                    PHP_URL_PATH
                ),
            'user' => $user,
        ]);
        ?>
    </aside>

    <main class="app-main">
        <header class="page-header">
            <div>
                <h1 class="page-title">
                    <?= e($pageTitle) ?>
                </h1>

                <?php if ($pageDescription !== ''): ?>
                    <p class="page-description">
                        <?= e($pageDescription) ?>
                    </p>
                <?php endif; ?>
            </div>
        </header>

        <?php
        if ($contentView === '') {
            throw new RuntimeException(
                'Layout content view was not supplied.'
            );
        }

        \view($contentView, $data);
        ?>
    </main>
</div>

<script
    src="/office_app/public/assets/js/app.js"
    defer
></script>
</body>
</html>