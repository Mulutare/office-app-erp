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
        'quick_sale' => ['Quick Sale', '/sales/quick-sale'],
        'dsa_dsp_report' => ['DSA/DSP Sales Report', '/sales/dsa-dsp-report', 'sales.view'],
        'incentives' => ['Incentives', '/sales/incentives', 'sales.incentive.view'],
        'orders' => ['Sales Orders', '/sales/orders'],
        'quotations' => ['Quotations', '/sales/quotations'],
        'customers' => ['Customers', '/sales/customers'],
        'products' => ['Products', '/sales/products'],
        'pricing' => ['Approved Pricing', '/sales/pricing', 'sales.pricing.view'],
        'product_variants' => ['Mobile / MiFi Variants', '/sales/product-variants', 'sales.view'],
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
        'dashboard' => ['Dashboard', '/finance'],
        'receivables' => ['Receivables', '/finance?section=receivables'],
        'invoices' => ['Customer Invoices', '/finance/customer-invoices'],
        'receipts' => ['Receipts', '/finance?section=receipts'],
        'settlements' => ['Sales Settlement Reconciliation', '/finance/settlements', 'finance.settlements.view'],
        'payables' => ['Payables', '/finance/accounting/payables', 'finance.records.view'],
        'expenses' => ['Expenses', '/finance/expenses', 'finance.records.view'],
        'legacy-expenses' => ['Expense History', '/finance?section=expenses'],
        'staff-loans' => ['Staff Loans & Advances', '/finance/staff-loans', 'finance.records.view'],
        'cash-bank' => ['Cash & Bank', '/finance/accounting/cash-bank', 'finance.records.view'],
        'bank-reconciliation' => ['Bank Reconciliation', '/finance/bank-reconciliation', 'finance.bank_reconciliation.view'],
        'accounts' => ['Chart of Accounts', '/finance/accounting/accounts', 'finance.records.view'],
        'journals' => ['Journals', '/finance?section=journals'],
        'ledger' => ['General Ledger', '/finance/accounting/ledger', 'finance.records.view'],
        'periods' => ['Accounting Periods', '/finance/accounting-periods', 'finance.period.view'],
        'reports' => ['Reports', '/finance/accounting/reports', 'finance.records.view'],
    ],
    'inventory' => [
        'stock' => ['Current Stock', '/inventory?section=stock'],
        'stock_requests' => ['Stock Requests', '/inventory/stock-requests', 'inventory.stock_requests.view'],
        'stock_daily_history' => ['Daily Stock History', '/inventory/stock-daily-history', 'inventory.stock.view'],
        'movements' => ['Movements', '/inventory?section=movements'],
        'receipts' => ['Receipts', '/inventory/receipts'],
        'transfers' => ['Transfers', '/inventory/transfers', 'inventory.transfers.view'],
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

$simpleSalesUser = !empty(
    $data['simpleSalesUser'] ?? false
);

if (
    $simpleSalesUser
    && $module === 'sales'
) {
    $definitions['sales'] = [
        'quick_sale' => [
            'Quick Sale',
            '/sales/quick-sale',
        ],
        'dsa_dsp_report' => [
            'Sales Report',
            '/sales/dsa-dsp-report',
            'sales.view',
        ],
        'incentives' => ['Incentives', '/sales/incentives', 'sales.incentive.view'],
    ];

    $section = $section !== '' ? $section : 'quick_sale';
}
if ($section === '') {
    if ($module === 'sales') {
        foreach (['dsa-dsp-report', 'incentives', 'quotations', 'orders', 'customers', 'product-variants', 'products', 'pricing', 'pricelists', 'teams', 'deliveries', 'settlements'] as $candidate) {
            if (str_contains($requestPath, '/sales/' . $candidate)) { $section = $candidate; break; }
        }
        if ($section === 'dsa-dsp-report') $section = 'dsa_dsp_report';
        if ($section === 'product-variants') $section = 'product_variants';
        $section = $section ?: 'orders';
    } elseif ($module === 'inventory') {
        foreach (['stock-requests', 'stock-daily-history', 'receipts', 'warehouses', 'locations'] as $candidate) {
            if (str_contains($requestPath, '/inventory/' . $candidate)) { $section = $candidate === 'stock-requests' ? 'stock_requests' : ($candidate==='stock-daily-history'?'stock_daily_history':$candidate); break; }
        }
        $section = $section ?: (string) ($_GET['section'] ?? 'stock');
    } elseif ($module === 'finance') {
        if (str_contains($requestPath, '/finance/accounting-periods')) {
            $section = 'periods';
        } elseif (str_contains($requestPath, '/finance/staff-loans')) {
            $section = 'staff-loans';
        } elseif (str_contains($requestPath, '/finance/expenses')) {
            $section = 'expenses';
        } elseif (str_contains($requestPath, '/finance/settlements')) {
            $section = 'settlements';
        } elseif (str_contains($requestPath, '/finance/bank-reconciliation')) {
            $section = 'bank-reconciliation';
        } elseif (str_contains($requestPath, '/finance/customer-invoices')) {
            $section = 'invoices';
        } elseif (preg_match('~/finance/accounting/([^/?]+)~', $requestPath, $matches)) {
            $section = $matches[1];
        } else {
            $section = (string) ($_GET['section'] ?? 'dashboard');
            if ($section === 'expenses') $section = 'legacy-expenses';
        }
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
<?php if ($module === 'finance'):
    $financeGroups = [
        'overview' => ['Overview', ['dashboard']],
        'receivables' => ['Receivables', ['receivables', 'invoices', 'receipts', 'ar-aging', 'customer-statements', 'ar-reconciliation']],
        'payables' => ['Payables & Expenses', ['payables', 'expenses', 'legacy-expenses', 'staff-loans', 'supplier-statements', 'ap-reconciliation']],
        'banking' => ['Banking & Cash', ['cash-bank', 'bank-reconciliation', 'settlements']],
        'accounting' => ['Accounting', ['accounts', 'journals', 'ledger', 'periods']],
        'reporting' => ['Reports', ['reports']],
    ];
    $financeLinks = array_replace($items, ['invoices' => ['Customer Invoices', '/finance/customer-invoices', 'finance.records.view']]) + [
        'ar-aging' => ['AR Aging', '/finance/accounting/receivables', 'finance.records.view'],
        'customer-statements' => ['Customer Statements', '/finance/statements/customer', 'finance.records.view'],
        'ar-reconciliation' => ['AR / GL Reconciliation', '/finance/reconciliation', 'finance.records.view'],
        'supplier-statements' => ['Supplier Statements', '/finance/statements/supplier', 'finance.records.view'],
        'ap-reconciliation' => ['AP / GL Reconciliation', '/finance/reconciliation?focus=ap', 'finance.records.view'],
    ];
    if (str_contains($requestPath, '/finance/statements/customer')) $section = 'customer-statements';
    elseif (str_contains($requestPath, '/finance/statements/supplier')) $section = 'supplier-statements';
    elseif (str_contains($requestPath, '/finance/reconciliation')) $section = ($_GET['focus'] ?? '') === 'ap' ? 'ap-reconciliation' : 'ar-reconciliation';
    elseif (str_contains($requestPath, '/finance/accounting/receivables')) $section = 'ar-aging';
    $currentGroup = 'overview';
    foreach ($financeGroups as $groupKey => [, $keys]) {
        if (in_array($section, $keys, true)) { $currentGroup = $groupKey; break; }
    }
    $linkVisible = static fn (array $item): bool => !isset($item[2]) || $can($item[2]);
?>
<nav class="finance-workspace-nav" aria-label="Finance workspace">
    <div class="finance-primary-nav" aria-label="Finance work centers">
        <?php foreach ($financeGroups as $groupKey => [$label, $keys]):
            $visibleKeys = array_values(array_filter($keys, static fn (string $key): bool => $linkVisible($financeLinks[$key])));
            if ($visibleKeys === []) continue;
            $path = $financeLinks[$visibleKeys[0]][1];
        ?>
        <a href="<?= e(appBasePath() . $path) ?>" class="finance-primary-link<?= $currentGroup === $groupKey ? ' active' : '' ?>"<?= $currentGroup === $groupKey ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="finance-secondary-nav" aria-label="<?= e($financeGroups[$currentGroup][0]) ?> pages">
        <?php foreach ($financeGroups[$currentGroup][1] as $key):
            $item = $financeLinks[$key]; if (!$linkVisible($item)) continue;
            [$label, $path] = $item;
            $actionCount = (int) ($actionRequiredCounts['finance'][$key] ?? 0);
        ?>
        <span class="module-tab-wrap<?= $actionCount > 0 ? ' has-action-badge' : '' ?>">
            <a class="finance-secondary-link<?= $section === $key ? ' active' : '' ?>" href="<?= e(appBasePath() . $path) ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php if ($actionCount > 0): $separator = str_contains($path, '?') ? '&' : '?'; ?>
            <a class="nav-action-badge" href="<?= e(appBasePath() . $path . $separator . 'task_filter=action_required') ?>" aria-label="<?= e('Show ' . $actionCount . ' records requiring action') ?>"><?= e($actionCount) ?></a>
            <?php endif; ?>
        </span>
        <?php endforeach; ?>
    </div>
</nav>
<?php else: ?>
<nav class="module-tabs<?= $module === 'finance' ? ' finance-module-tabs' : '' ?>" aria-label="<?= e(ucfirst($module)) ?> sections">
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
<?php endif; ?>
