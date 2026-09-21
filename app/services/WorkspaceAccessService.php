<?php

declare(strict_types=1);
namespace App\Services;

/** Landing-page contract. Controllers retain record, workflow and action checks. */
final class WorkspaceAccessService
{
    public static function definitions(): array
    {
        return [
    'dashboard' => ['dashboard'=>['Dashboard','/dashboard','dashboard.view']],
    'analytics' => ['analytics'=>['Analytics','/analytics','analytics.view']],
    'hr' => ['employees'=>['Human Resources','/hr',['hr.records.view','hr.records.manage','hr.leave.view','hr.leave.manage','hr.leave.approve','hr.leave.self.view','hr.leave.self.request','hr.leave.team.approve','hr.leave.policy.manage','hr.leave.balance.manage']]],
    'attendance' => ['records'=>['Attendance','/attendance',['attendance.records.view','attendance.records.manage']]],
    'administration' => ['access_control'=>['Access Control','/administration/access-control','administration.roles.manage'], 'users'=>['Users','/administration/users','administration.users.manage']],
    'sales' => [
        'quick_sale' => ['Quick Sale', '/sales/quick-sale', ['sales.quick_sale.use', 'sales.quick_sale.review']],
        'dsa_dsp_report' => ['DSA/DSP Sales Report', '/sales/dsa-dsp-report', ['sales.report.submit', 'sales.report.review']],
        'incentives' => ['Incentives', '/sales/incentives', 'sales.incentive.view'],
        'orders' => ['Sales Orders', '/sales/orders', 'sales.orders.view', 'sales.view'],
        'quotations' => ['Quotations', '/sales/quotations', 'sales.quotations.view', 'sales.view'],
        'customers' => ['Customers', '/sales/customers', 'sales.customers.view', 'sales.view'],
        'products' => ['Products', '/sales/products', 'sales.products.view', 'sales.view'],
        'pricing' => ['Selling Terms', '/sales/pricing', 'sales.pricing.view'],
        'product_variants' => ['Mobile / MiFi Variants', '/sales/product-variants', 'sales.catalogue.manage'],
        'pricelists' => ['Pricelists', '/sales/pricelists', 'sales.pricing.view'],
        'teams' => ['DSA / DSP & Teams', '/sales/teams', 'sales.catalogue.manage'],
        'deliveries' => ['Deliveries', '/sales/deliveries', 'sales.deliveries.view', 'sales.view'],
        'settlements' => ['Settlements', '/sales/settlements', 'sales.settlements.view'],
    ],
    'procurement' => [
        'overview' => ['Overview', '/procurement?section=overview', 'procurement.overview.view', 'procurement.view'],
        'requisitions' => ['Requisitions', '/procurement?section=requisitions', 'procurement.requisitions.view', 'procurement.view'],
        'orders' => ['Purchase Orders', '/procurement?section=orders', 'procurement.orders.view', 'procurement.view'],
        'suppliers' => ['Suppliers', '/procurement?section=suppliers', 'procurement.suppliers.view', 'procurement.view'],
        'receipts' => ['Receipts', '/procurement?section=receipts', 'procurement.receipts.create'],
        'bills' => ['Supplier Bills', '/procurement?section=bills', 'procurement.bills.view', 'procurement.view'],
        'payments' => ['Payments', '/procurement?section=payments', 'procurement.payments.post'],
        'returns' => ['Returns', '/procurement?section=returns', 'procurement.returns.post'],
    ],
    'finance' => [
        'dashboard' => ['Dashboard', '/finance', 'finance.dashboard.view', 'finance.records.view'],
        'receivables' => ['Receivables', '/finance?section=receivables', 'finance.receivables.view', 'finance.records.view'],
        'invoices' => ['Customer Invoices', '/finance/customer-invoices', 'finance.invoices.view', 'finance.records.view'],
        'receipts' => ['Receipts', '/finance?section=receipts', 'finance.receipts.view', 'finance.records.view'],
        'settlements' => ['Sales Settlement Reconciliation', '/finance/settlements', 'finance.settlements.view'],
        'payables' => ['Payables', '/finance/accounting/payables', 'finance.payables.view', 'finance.records.view'],
        'expenses' => ['Expenses', '/finance/expenses', 'finance.expenses.view', 'finance.records.view'],
        'legacy-expenses' => ['Expense History', '/finance?section=expenses', 'finance.expense_history.view', 'finance.records.view'],
        'staff-loans' => ['Staff Loans & Advances', '/finance/staff-loans', 'finance.staff_loans.view', 'finance.records.view'],
        'cash-bank' => ['Cash & Bank', '/finance/accounting/cash-bank', 'finance.cash_bank.view', 'finance.records.view'],
        'bank-reconciliation' => ['Bank Reconciliation', '/finance/bank-reconciliation', 'finance.bank_reconciliation.view'],
        'accounts' => ['Chart of Accounts', '/finance/accounting/accounts', 'finance.accounts.view', 'finance.records.view'],
        'journals' => ['Journals', '/finance?section=journals', 'finance.journals.view', 'finance.records.view'],
        'ledger' => ['General Ledger', '/finance/accounting/ledger', 'finance.ledger.view', 'finance.records.view'],
        'periods' => ['Accounting Periods', '/finance/accounting-periods', 'finance.period.view'],
        'reports' => ['Reports', '/finance/accounting/reports', 'finance.reports.view', 'finance.records.view'],
    ],
    'inventory' => [
        'stock' => ['Current Stock', '/inventory?section=stock', 'inventory.stock.view'],
        'stock_requests' => ['Stock Requests', '/inventory/stock-requests', 'inventory.stock_requests.view'],
        'stock_daily_history' => ['Daily Stock History', '/inventory/stock-daily-history', 'inventory.history.view', 'inventory.stock.view'],
        'movements' => ['Movements', '/inventory?section=movements', 'inventory.movements.view', 'inventory.stock.view'],
        'receipts' => ['Receipts', '/inventory/receipts', 'inventory.receipts.view'],
        'transfers' => ['Transfers', '/inventory/transfers', 'inventory.transfers.view'],
        'warehouses' => ['Warehouses', '/inventory/warehouses', 'inventory.warehouses.view'],
        'locations' => ['Locations', '/inventory/locations', 'inventory.locations.view', 'inventory.warehouses.view'],
    ],
    'assets' => [
        'register' => ['Asset Register', '/assets-management?section=register', 'assets.view'],
        'direct' => ['Direct Assets', '/assets-management?section=direct', 'assets.direct.manage', 'assets.manage'],
        'categories' => ['Asset Categories', '/assets-management?section=categories', 'assets.categories.manage', 'assets.manage'],
        'capitalization' => ['Capitalization', '/assets-management?section=capitalization', 'assets.inventory.capitalize'],
    ],
];
    }

    public static function allowed(array $item, ?array $permissions = null): bool
    {
        $permissions ??= (array)($_SESSION['auth']['permissions'] ?? []);
        $can = static fn(string $code): bool => in_array($code, $permissions, true);
        $permission = $item[2];
        if (array_intersect((array)$permission, $permissions) === []) return false;
        $module = EffectivePermissionPolicy::module((string)((array)$permission)[0]);
        if ($module !== null && !$can($module.'.module.enabled')) return false;
        if (isset($item[3]) && !$can($item[3])) return false;
        $path = $item[1];
        if (str_starts_with($path, '/procurement') && !$can('procurement.view')) return false;
        if (str_starts_with($path, '/assets-management') && !$can('assets.view')) return false;
        if (in_array($path, ['/sales/pricelists','/sales/teams'], true) && !$can('sales.view')) return false;
        return true;
    }

    /** Match a business URL to its landing function, including child record/action routes. */
    public static function forPath(string $url): ?array
    {
        $path = rtrim((string)parse_url($url, PHP_URL_PATH), '/') ?: '/';
        parse_str((string)(parse_url($url, PHP_URL_QUERY) ?? ''), $query);
        $best = null; $length = -1;
        foreach (self::definitions() as $module => $items) {
            foreach ($items as $key => $item) {
                $base = (string)parse_url($item[1], PHP_URL_PATH);
                parse_str((string)(parse_url($item[1], PHP_URL_QUERY) ?? ''), $itemQuery);
                if ($path !== $base && !str_starts_with($path, $base.'/')) continue;
                if (!isset($itemQuery['section']) && substr_count($base, '/') === 1) {
                    if ($path !== $base || isset($query['section'])) continue;
                }
                if (in_array($base, ['/dashboard','/hr','/attendance','/analytics'], true) && $path !== $base) continue;
                if (isset($itemQuery['section'])) {
                    $section = $query['section'] ?? ($path === $base ? array_key_first($items) : explode('/', substr($path, strlen($base)+1))[0]);
                    if ($section !== $itemQuery['section']) continue;
                }
                if (strlen($base) > $length) { $best = $item; $length = strlen($base); }
            }
        }
        if (preg_match('~^/sales/quick-sale/\\d+(?:/|$)~', $path)) {
            $best = self::definitions()['sales']['quick_sale'];
            $best[2] = ['sales.quick_sale.use','sales.quick_sale.review','sales.report.submit','sales.report.review'];
        }
        if ($best === null && $path === '/finance') return self::definitions()['finance']['dashboard'];
        if ($best === null && $path === '/inventory') return self::definitions()['inventory']['stock'];
        if ($best === null && $path === '/procurement') return self::definitions()['procurement']['overview'];
        if (preg_match('~^/procurement/\\d+(?:/|$)~', $path)) return self::definitions()['procurement']['orders'];
        if (preg_match('~^/assets-management/\\d+(?:/|$)~', $path)) return self::definitions()['assets']['register'];
        $aliases = ['/finance/accounting/receivables'=>'receivables', '/finance/statements/customer'=>'receivables', '/finance/statements/supplier'=>'payables', '/finance/reconciliation'=>'reports'];
        if (isset($aliases[$path])) return self::definitions()['finance'][$aliases[$path]];
        if ($path === '/sales') return self::definitions()['sales']['orders'];
        return $best;
    }

    public static function requirePath(string $url): void
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $prefix = explode('/', ltrim($path, '/'))[0];
        $module = $prefix === 'assets-management' ? 'assets' : $prefix;
        if (!in_array($module, EffectivePermissionPolicy::MODULES, true)) return;
        $authorization = new AuthorizationService();
        $authorization->requirePermission($module.'.module.enabled');
        $item = self::forPath($url);
        if ($item !== null && !self::allowed($item)) {
            http_response_code(403);
            \view('errors.403', ['applicationName'=>\config('name','OfficeApp ERP')]);
            exit;
        }
    }

    public static function firstLanding(): string
    {
        foreach (array_keys(self::definitions()) as $module) {
            $landing = self::landing($module);
            if ($landing !== null) return $landing;
        }
        return '/account';
    }

    public static function landing(string $module): ?string
    {
        foreach (self::definitions()[$module] ?? [] as $item) {
            if (self::allowed($item)) return $item[1];
        }
        return null;
    }
}
