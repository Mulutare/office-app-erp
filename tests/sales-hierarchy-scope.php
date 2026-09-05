<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

use App\Services\SalesHierarchyScope;

// Local integration fixtures are rolled back, including hierarchy and role changes.
if (getenv('APP_ENV') !== 'development' && getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('Run this test only in development/testing.');
}
$db = db();
$passed = $failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$db->beginTransaction();
try {
    $company = (int) $db->query('SELECT company_id FROM company_user_roles ORDER BY company_id LIMIT 1')->fetchColumn();
    $suffix = bin2hex(random_bytes(5));
    $create = static function (string $label, ?int $parent, string $role) use ($db, $company, $suffix): int {
        $name = 'scope_' . $label . '_' . $suffix;
        $db->prepare('INSERT INTO users(username,email,password_hash,display_name,must_change_password) VALUES(?,?,?,?,0)')
            ->execute([$name, $name . '@example.test', password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $label]);
        $id = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO company_users(company_id,user_id,manager_user_id,active) VALUES(?,?,?,1)')->execute([$company, $id, $parent]);
        $db->prepare('INSERT INTO company_user_roles(company_id,user_id,role_id) SELECT ?,?,role_id FROM roles WHERE code=?')->execute([$company, $id, $role]);
        return $id;
    };
    $top = $create('top', null, 'sales_manager');
    $middle = $create('middle', $top, 'sales_manager');
    $leaf = $create('leaf', $middle, 'sales_cashier');
    $other = $create('other', null, 'sales_manager');
    $finance = $create('finance', null, 'finance_officer');
    $admin = $create('owner', null, 'company_owner');
    $scope = new SalesHierarchyScope();
    $check($scope->userIds($company, $top) === [$top, $middle, $leaf], 'Top manager recursively sees only own descendants');
    $check($scope->userIds($company, $middle) === [$middle, $leaf], 'Middle manager cannot see ancestors or another branch');
    $check($scope->userIds($company, $leaf) === [$leaf], 'Leaf sees only self');
    $check($scope->canReadOwner($company, $top, $leaf), 'Parent reads descendant owner without job-title matching');
    $check(!$scope->canReadOwner($company, $other, $leaf), 'Unrelated manager denied');
    $check(!$scope->canReadSalesRow($company, $finance, ['created_by' => $leaf]), 'Finance permission does not grant Sales operational access');
    $check(!$scope->hasCompanyWideAccess($company, $top), 'Sales manager catalogue permissions do not grant global scope');
    $check($scope->canReadSalesRow($company, $admin, ['created_by' => $other]), 'Company owner retains company-wide Sales access');
    $check(!$scope->canReadSalesRow($company, $admin, ['company_id' => $company + 9999, 'created_by' => $admin]), 'Admin cannot read a cross-company row');
    $check($scope->userIds($company + 9999, $top) === [], 'Foreign tenant membership denied');
    $check($scope->parentId($company, $middle) === $top, 'Direct parent resolved');
    $db->prepare('UPDATE company_users SET manager_user_id=? WHERE company_id=? AND user_id=?')->execute([$leaf, $company, $top]);
    $blocked = false;
    try { $scope->parentId($company, $middle); } catch (RuntimeException) { $blocked = true; }
    $check($blocked, 'Upward cycle rejected');
    $db->prepare('UPDATE company_users SET manager_user_id=NULL WHERE company_id=? AND user_id=?')->execute([$company, $top]);
    $db->prepare('UPDATE users SET active=0 WHERE user_id=?')->execute([$top]);
    $blocked = false;
    try { $scope->parentId($company, $middle); } catch (RuntimeException) { $blocked = true; }
    $check($blocked, 'Inactive parent rejected');
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL unexpected: ' . $e->getMessage() . PHP_EOL;
} finally {
    if ($db->inTransaction()) $db->rollBack();
}
echo "$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
