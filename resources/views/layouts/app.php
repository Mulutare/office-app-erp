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

$companies = is_array(
    $user['companies'] ?? null
)
    ? $user['companies']
    : [];

$currentCompanyId = (int) (
    $user['company']['company_id'] ?? 0
);

$companySwitchError = getFlash(
    'company_switch_error'
);

$companySwitchSuccess = getFlash(
    'company_switch_success'
);
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
        href="<?= e(assetUrl('css/app.css')) ?>"
    >
</head>

<body data-app-base="<?= e(appBasePath()) ?>">
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
                src="<?= e(assetUrl(
                    'images/company-logo.png'
                )) ?>"
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
                    <?= e(
                        $user['company']['name']
                        ?? 'Enterprise Management System'
                    ) ?>
                </p>
            </div>
        </div>

        <div class="header-actions">
            <?php if (count($companies) > 1): ?>
                <form
                    method="post"
                    action="/office_app/public/company/switch"
                    class="company-switcher"
                >
                    <?= csrfField() ?>

                    <label for="company_id">
                        Workspace
                    </label>

                    <select
                        id="company_id"
                        name="company_id"
                        aria-label="Company workspace"
                    >
                        <?php foreach (
                            $companies as $company
                        ): ?>
                            <?php
                            $companyId = (int) (
                                $company['company_id']
                                ?? 0
                            );
                            ?>
                            <option
                                value="<?= e($companyId) ?>"
                                <?= $companyId
                                    === $currentCompanyId
                                        ? 'selected'
                                        : '' ?>
                            >
                                <?= e(
                                    $company['name']
                                    ?? ''
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button
                        type="submit"
                        class="btn btn-secondary btn-compact"
                    >
                        Switch
                    </button>
                </form>
            <?php endif; ?>

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
        <?php if (
            is_string($companySwitchError)
            && $companySwitchError !== ''
        ): ?>
            <div
                class="alert alert-danger"
                role="alert"
            >
                <?= e($companySwitchError) ?>
            </div>
        <?php endif; ?>

        <?php if (
            is_string($companySwitchSuccess)
            && $companySwitchSuccess !== ''
        ): ?>
            <div
                class="alert alert-success"
                role="status"
            >
                <?= e($companySwitchSuccess) ?>
            </div>
        <?php endif; ?>

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

        <?php \view('layouts.module-navigation', $data); ?>

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
    src="<?= e(assetUrl('js/app.js')) ?>"
    defer
></script>
</body>
</html>
