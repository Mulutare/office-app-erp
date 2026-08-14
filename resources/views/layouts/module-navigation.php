<?php

declare(strict_types=1);

$requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$moduleContext = is_array($data['moduleContext'] ?? null) ? $data['moduleContext'] : [];
$module = (string) ($moduleContext['module'] ?? '');
$section = (string) ($moduleContext['section'] ?? '');
$permissions = is_array($data['user']['permissions'] ?? null) ? $data['user']['permissions'] : [];
$can = static fn (string $permission): bool => in_array($permission, $permissions, true);

if ($module === '') {
    foreach (['sales', 'procurement', 'finance', 'inventory'] as $candidate) {
        if (str_starts_with($requestPath, '/office_app/public/' . $candidate)) {
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
        'teams' => ['Sales Teams', '/sales/teams'],
        'deliveries' => ['Deliveries', '/sales/deliveries'],
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
        'expenses' => ['Expenses', '/finance?section=expenses'],
    ],
    'inventory' => [
        'stock' => ['Current Stock', '/inventory?section=stock'],
        'movements' => ['Movements', '/inventory?section=movements'],
        'receipts' => ['Receipts', '/inventory/receipts'],
        'warehouses' => ['Warehouses', '/inventory/warehouses', 'inventory.warehouses.view'],
        'locations' => ['Locations', '/inventory/locations', 'inventory.warehouses.view'],
    ],
];

if ($section === '') {
    if ($module === 'sales') {
        foreach (['quotations', 'orders', 'customers', 'products', 'pricelists', 'teams', 'deliveries'] as $candidate) {
            if (str_contains($requestPath, '/sales/' . $candidate)) { $section = $candidate; break; }
        }
        $section = $section ?: 'orders';
    } elseif ($module === 'inventory') {
        foreach (['receipts', 'warehouses', 'locations'] as $candidate) {
            if (str_contains($requestPath, '/inventory/' . $candidate)) { $section = $candidate; break; }
        }
        $section = $section ?: (string) ($_GET['section'] ?? 'stock');
    } elseif ($module === 'finance') {
        $section = str_contains($requestPath, '/finance/customer-invoices') ? 'invoices' : (string) ($_GET['section'] ?? 'receivables');
    } elseif ($module === 'procurement') {
        $section = (string) ($moduleContext['section'] ?? $_GET['section'] ?? (preg_match('~/procurement/\d+~', $requestPath) ? 'orders' : 'overview'));
    }
}

$items = $definitions[$module] ?? [];
if ($items !== []):
?>
<nav class="module-tabs" aria-label="<?= e(ucfirst($module)) ?> sections">
    <?php foreach ($items as $key => $item): ?>
        <?php [$label, $path] = $item; $permission = $item[2] ?? null; ?>
        <?php if ($permission !== null && !$can($permission) && !($permission === 'inventory.warehouses.view' && $can('inventory.warehouses.manage'))) continue; ?>
        <a class="module-tab <?= $section === $key ? 'active' : '' ?>" href="<?= e(appBasePath() . $path) ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
