<?php

declare(strict_types=1);

$requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$moduleContext = is_array($data['moduleContext'] ?? null) ? $data['moduleContext'] : [];
$module = (string) ($moduleContext['module'] ?? '');
$section = (string) ($moduleContext['section'] ?? '');
$permissions = is_array($data['user']['permissions'] ?? null) ? $data['user']['permissions'] : [];
$can = static fn (string $permission): bool => in_array($permission, $permissions, true);
$actionRequiredCounts = is_array($data['actionRequiredCounts'] ?? null) ? $data['actionRequiredCounts'] : [];

if ($module === '') {
    foreach (['sales', 'procurement', 'finance', 'inventory', 'assets'] as $candidate) {
        if (str_starts_with($requestPath, "/" . $candidate) || str_starts_with($requestPath, "/office_app/public/" . $candidate)) {
            $module = $candidate;
            break;
        }
    }
}

$definitions = [
    'sales' => [
        'orders' => ['Sales Orders', '/sales/orders'],
        'quotations' => ['Quotations', '/sales/quotations'],
        'customers' => ['Customers', '/sales/customers'],
        'products' => ['Products', '/sales/products'],
        'pricelists' => ['Pricelists', '/sales/pricelists'],
        'teams' => ['DSA / DSP & Teams', '/sales/teams'],
        'deliveries' => ['Deliveries', '/sales/deliveries'],
        'settlements' => ['Settlements', '/sales/settlements', 'sales.settlements.view'],
    ],
    'procurement' => [
        'overview' => ['Overview', '/procurement?section=overview'],
        'requisitions' => ['Requisitions', '/procurement?section=requisitions'],
        'orders' => ['Purchase Orders', '/procurement?section=orders'],
        'suppliers' => ['Suppliers', '/procurement?section=suppliers'],
        'receipts' => ['Receipts', '/procurement?section=receipts', 'procurement.receipts.create'],
        'bills' => ['Supplier Bills', '/procurement?section=bills'],
        'payments' => ['Payments', '/procurement?section=payments', 'procurement.payments.post'],
        'returns' => ['Returns', '/procurement?section=returns', 'procurement.returns.post'],
    ],
    'finance' => [
        'receivables' => ['Receivables', '/finance?section=receivables'],
        'invoices' => ['Customer Invoices', '/finance/customer-invoices'],
        'journals' => ['Journals', '/finance?section=journals'],
        'receipts' => ['Receipts', '/finance?section=receipts'],
        'settlements' => ['Settlement Reconciliation', '/finance/settlements', 'finance.settlements.view'],
        'expenses' => ['Expenses', '/finance?section=expenses'],
        'periods' => ['Accounting Periods', '/finance/accounting-periods', 'finance.period.view'],
    ],
    'inventory' => [
        'stock' => ['Current Stock', '/inventory?section=stock'],
        'movements' => ['Movements', '/inventory?section=movements'],
        'receipts' => ['Receipts', '/inventory/receipts'],
        'warehouses' => ['Warehouses', '/inventory/warehouses', 'inventory.warehouses.view'],
        'locations' => ['Locations', '/inventory/locations', 'inventory.warehouses.view'],
    ],
    'assets' => [
        'register' => ['Asset Register', '/assets-management?section=register', 'assets.view'],
        'direct' => ['Direct Assets', '/assets-management?section=direct', 'assets.manage'],
        'categories' => ['Asset Categories', '/assets-management?section=categories', 'assets.manage'],
        'capitalization' => ['Capitalization', '/assets-management?section=capitalization', 'assets.inventory.capitalize'],
    ],
];

if ($section === '') {
    if ($module === 'sales') {
        foreach (['quotations', 'orders', 'customers', 'products', 'pricelists', 'teams', 'deliveries', 'settlements'] as $candidate) {
            if (str_contains($requestPath, '/sales/' . $candidate)) { $section = $candidate; break; }
        }
        $section = $section ?: 'orders';
    } elseif ($module === 'inventory') {
        foreach (['receipts', 'warehouses', 'locations'] as $candidate) {
            if (str_contains($requestPath, '/inventory/' . $candidate)) { $section = $candidate; break; }
        }
        $section = $section ?: (string) ($_GET['section'] ?? 'stock');
    } elseif ($module === 'finance') {
        $section = str_contains($requestPath, '/finance/accounting-periods') ? 'periods' : (str_contains($requestPath, '/finance/settlements') ? 'settlements' : (str_contains($requestPath, '/finance/customer-invoices') ? 'invoices' : (string) ($_GET['section'] ?? 'receivables')));
    } elseif ($module === 'procurement') {
        $section = (string) ($moduleContext['section'] ?? $_GET['section'] ?? (preg_match('~/procurement/\d+~', $requestPath) ? 'orders' : 'overview'));
    } elseif ($module === 'assets') {
        $section = (string) ($_GET['section'] ?? 'register');
    }
}
if ($module === 'assets' && !in_array($section, ['register', 'direct', 'categories', 'capitalization'], true)) {
    $section = 'register';
}

$items = $definitions[$module] ?? [];
if ($items !== []):
?>
<nav class="module-tabs" aria-label="<?= e(ucfirst($module)) ?> sections">
    <?php foreach ($items as $key => $item): ?>
        <?php [$label, $path] = $item; $permission = $item[2] ?? null; ?>
        <?php if ($permission !== null && !$can($permission) && !($permission === 'inventory.warehouses.view' && $can('inventory.warehouses.manage'))) continue; ?>
        <?php $actionCount = (int) ($actionRequiredCounts[$module][$key] ?? 0); ?>
        <span class="module-tab-wrap<?= $actionCount > 0 ? ' has-action-badge' : '' ?>">
        <a class="module-tab <?= $section === $key ? 'active' : '' ?>" href="<?= e(appBasePath() . $path) ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>>
            <span><?= e($label) ?></span>
        </a>
        <?php if ($actionCount > 0): ?>
            <?php $separator = str_contains($path, '?') ? '&' : '?'; ?>
            <a class="nav-action-badge" href="<?= e(appBasePath() . $path . $separator . 'task_filter=action_required') ?>" aria-label="<?= e('Show ' . $actionCount . ' ' . ($actionCount === 1 ? 'record' : 'records') . ' requiring action') ?>"><?= e($actionCount) ?></a>
        <?php endif; ?>
        </span>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
