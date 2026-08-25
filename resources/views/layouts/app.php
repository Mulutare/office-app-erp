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

$actionRequiredCounts = (new \App\Services\ActionRequiredCountService())->counts(
    $currentCompanyId,
    (int) ($user['user_id'] ?? 0),
    is_array($user['permissions'] ?? null) ? $user['permissions'] : []
);
$data['actionRequiredCounts'] = $actionRequiredCounts;

$requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$taskModule = (string) ($data['moduleContext']['module'] ?? '');
$taskSection = (string) ($data['moduleContext']['section'] ?? '');
if ($taskModule === '') {
    foreach (['sales', 'procurement', 'finance', 'inventory', 'hr', 'administration'] as $candidate) {
        if (str_contains($requestPath, '/' . $candidate)) { $taskModule = $candidate; break; }
    }
}
if ($taskSection === '') {
    if ($taskModule === 'sales') $taskSection = (string) ($data['salesSection'] ?? (preg_match('~/sales/(quotations|orders|deliveries|settlements)~', $requestPath, $match) ? $match[1] : 'orders'));
    elseif ($taskModule === 'procurement') $taskSection = (string) ($_GET['section'] ?? (preg_match('~/procurement/\d+~', $requestPath) ? 'orders' : 'overview'));
    elseif ($taskModule === 'finance') $taskSection = str_contains($requestPath, '/customer-invoices') ? 'invoices' : (str_contains($requestPath, '/settlements') ? 'settlements' : (string) ($_GET['section'] ?? 'receivables'));
    elseif ($taskModule === 'inventory') $taskSection = str_contains($requestPath, '/receipts') ? 'receipts' : (string) ($_GET['section'] ?? 'stock');
    elseif ($taskModule === 'hr') $taskSection = str_contains($requestPath, '/leave') ? 'leave' : '';
    elseif ($taskModule === 'administration') $taskSection = str_contains($requestPath, '/integration-events') ? 'integration_events' : '';
}
$data['actionRequiredModule'] = $taskModule;
$data['actionRequiredSection'] = $taskSection;
$data['actionRequiredItems'] = (new \App\Services\ActionRequiredCountService())->itemsFor(
    $currentCompanyId,
    (int) ($user['user_id'] ?? 0),
    is_array($user['permissions'] ?? null) ? $user['permissions'] : [],
    $taskModule,
    $taskSection
);
$data['actionRequiredFilter'] = (string) ($_GET['task_filter'] ?? '') === 'action_required';

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

        <?php \view('layouts.action-required-items', $data); ?>

        <?php
        if ($contentView === '') {
            throw new RuntimeException(
                'Layout content view was not supplied.'
            );
        }

        if (empty($data['actionRequiredFilter'])) {
            \view($contentView, $data);
        }
        ?>
    </main>
</div>

<script
    src="<?= e(assetUrl('js/app.js')) ?>"
    defer
></script>
</body>
</html>
