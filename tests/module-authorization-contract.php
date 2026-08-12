<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;
$checks = 0;
$check = static function (bool $condition, string $description) use (&$failures, &$checks): void {
    $checks++;
    fwrite($condition ? STDOUT : STDERR, ($condition ? 'PASS ' : 'FAIL ') . $description . PHP_EOL);
    $failures += $condition ? 0 : 1;
};
$source = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$authorization = $source('app/services/AuthorizationService.php');
$sales = $source('app/controllers/SalesController.php');
$inventory = $source('app/controllers/InventoryController.php');
$warehouse = $source('app/controllers/WarehouseController.php');
$location = $source('app/controllers/WarehouseLocationController.php');
$finance = $source('app/controllers/FinanceController.php');
$roles = $source('app/repositories/MySql/RoleRepository.php');
$apiSecurity = $source('app/services/ApiSecurityService.php');
$updates = $source('app/services/CompanyUpdateService.php');
$provisioning = $source('app/services/CompanyProvisioningService.php');
$dependencies = $source('database/migrations/mysql/047_harden_module_release_licensing.php');

$check(str_contains($authorization, 'function requireModulePermission('), 'Authorization centralizes module plus tenant-permission enforcement');
$check(substr_count($sales, '$this->authorizeInventoryDelivery();') === 3, 'All three Sales inventory-backed delivery mutations use the cross-module gate');
$check(str_contains($sales, "requireModule('sales')") && str_contains($sales, "'inventory.transfers.manage'"), 'Sales delivery mutations require Sales, Inventory, and the Inventory transfer permission');
$check(substr_count($sales, "requireModulePermission('finance','finance.records.manage')") === 2, 'Invoice and credit-note mutations preserve Finance gating');
foreach ([$inventory, $warehouse, $location] as $controller) {
    $check(str_contains($controller, 'requireModulePermission(') && str_contains($controller, "'inventory'"), 'Inventory-owned controller enforces entitlement and permission');
}
$check(str_contains($finance, "requireModulePermission(\n            'finance'") && str_contains($finance, "'finance.records.view'") && str_contains($finance, "'finance.records.manage'"), 'Finance operations enforce entitlement and permission before controller work');
$check(str_contains($sales, "requireModulePermission('sales', \$permission)") && str_contains($sales, "requireModulePermission('finance','finance.records.manage')"), 'Sales direct and Finance-backed operations cannot bypass module licensing');
$check(str_contains($sales, "requireModulePermission('sales', \$permission)"), 'Sales requires its permission while licensed');
$check(str_contains($inventory, "'inventory',\n            \$permission"), 'Inventory requires its permission while licensed');
$check(str_contains($finance, "'finance',\n            \$permission"), 'Finance requires its permission while licensed');
$replaceStart = strpos($roles, 'function replacePermissions(');
$copyStart = strpos($roles, 'function copyPermissionTemplatesToCompany(');
$replaceSource = substr($roles, (int) $replaceStart, (int) $copyStart - (int) $replaceStart);
$copySource = substr($roles, (int) $copyStart);
$check(str_contains($replaceSource, 'INSERT INTO company_role_permissions') && !str_contains($replaceSource, 'INSERT IGNORE'), 'Role permission replacement fails loudly on invalid inserts');
$check(str_contains($copySource, 'INSERT IGNORE INTO company_role_permissions'), 'Initial template copy is idempotent and preserves existing grant attribution');
$check(!str_contains($updates, 'copyPermissionTemplatesToCompany'), 'Company updates never reapply global permission templates');
$check(substr_count($provisioning, 'copyPermissionTemplatesToCompany') === 1, 'Initial company provisioning copies default permission templates once');
$check(str_contains($apiSecurity, 'enabledForCompany($companyId)') && !str_contains($apiSecurity, 'SELECT COUNT(*) FROM company_modules'), 'Sales API uses the authoritative effective-entitlement query');
$check(!str_contains($dependencies, "assets.code='sales'") && str_contains($dependencies, "assets.code='assets'") && str_contains($dependencies, "finance.code='finance'"), 'Declared dependency is Assets to Finance, not Sales to Inventory');

echo sprintf("%d module authorization checks, %d failures\n", $checks, $failures);
exit($failures === 0 ? 0 : 1);
