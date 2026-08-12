<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

use App\Services\CompanyUpdateService;

$pdo = db();
$failures = 0;
$checks = 0;
$check = static function (bool $condition, string $description) use (&$failures, &$checks): void {
    $checks++;
    fwrite($condition ? STDOUT : STDERR, ($condition ? 'PASS ' : 'FAIL ') . $description . PHP_EOL);
    $failures += $condition ? 0 : 1;
};

$actor = $pdo->query(
    'SELECT user_id FROM users WHERE active=TRUE AND is_platform_admin=TRUE ORDER BY user_id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
$company = $pdo->query(
    "SELECT * FROM companies WHERE deleted_at IS NULL AND approval_status='approved' ORDER BY company_id LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$actorId = (int) ($actor['user_id'] ?? 0);
$companyId = (int) ($company['company_id'] ?? 0);
$check($actorId > 0 && $companyId > 0, 'Company-update fixtures exist');

if ($actorId < 1 || $companyId < 1) {
    exit(1);
}

$grant = $pdo->query(
    'SELECT company_id,role_id,permission_id,granted_by FROM company_role_permissions '
    . 'WHERE company_id=' . $companyId . ' ORDER BY role_id,permission_id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
$check(is_array($grant), 'Company has a removable role permission');
if (!is_array($grant)) {
    exit(1);
}

try {
    $modules = $pdo->query(
        'SELECT modules.code FROM company_modules entitlements '
        . 'INNER JOIN erp_modules modules ON modules.module_id=entitlements.module_id '
        . 'WHERE entitlements.company_id=' . $companyId
        . " AND modules.release_status='released'"
        . " AND entitlements.license_status IN('active','trial') ORDER BY modules.code"
    )->fetchAll(PDO::FETCH_COLUMN);
    $beforeEntitlements = $pdo->query(
        'SELECT module_id,enabled,license_status,expires_at FROM company_modules '
        . 'WHERE company_id=' . $companyId . ' ORDER BY module_id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $beforeAttribution = $pdo->query(
        'SELECT role_id,permission_id,granted_by FROM company_role_permissions '
        . 'WHERE company_id=' . $companyId . ' ORDER BY role_id,permission_id'
    )->fetchAll(PDO::FETCH_ASSOC);

    $delete = $pdo->prepare(
        'DELETE FROM company_role_permissions WHERE company_id=:company AND role_id=:role AND permission_id=:permission'
    );
    $delete->execute([
        'company' => $companyId,
        'role' => (int) $grant['role_id'],
        'permission' => (int) $grant['permission_id'],
    ]);

    $input = [
        'name' => (string) $company['name'],
        'legal_name' => $company['legal_name'],
        'contact_email' => $company['contact_email'],
        'contact_phone' => $company['contact_phone'],
        'country_code' => (string) $company['country_code'],
        'default_currency' => (string) $company['default_currency'],
        'timezone' => (string) $company['timezone'],
        'subscription_status' => (string) $company['subscription_status'],
        'subscription_expires_at' => $company['subscription_expires_at'] === null
            ? ''
            : substr((string) $company['subscription_expires_at'], 0, 10),
        'brand_primary_color' => (string) $company['brand_primary_color'],
        'module_codes' => array_values(array_map('strval', $modules)),
    ];
    $result = (new CompanyUpdateService())->update($companyId, $input, $actorId);
    $check(!empty($result['successful']), 'Company update completes without SQLSTATE 23000');

    $removedCount = $pdo->prepare(
        'SELECT COUNT(*) FROM company_role_permissions WHERE company_id=:company AND role_id=:role AND permission_id=:permission'
    );
    $removedCount->execute([
        'company' => $companyId,
        'role' => (int) $grant['role_id'],
        'permission' => (int) $grant['permission_id'],
    ]);
    $check((int) $removedCount->fetchColumn() === 0, 'Intentionally removed permission remains removed');

    $afterAttribution = $pdo->query(
        'SELECT role_id,permission_id,granted_by FROM company_role_permissions '
        . 'WHERE company_id=' . $companyId . ' ORDER BY role_id,permission_id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $expectedAttribution = array_values(array_filter(
        $beforeAttribution,
        static fn (array $row): bool => !(
            (int) $row['role_id'] === (int) $grant['role_id']
            && (int) $row['permission_id'] === (int) $grant['permission_id']
        )
    ));
    $check($afterAttribution === $expectedAttribution, 'Existing granted_by values remain unchanged');

    $afterEntitlements = $pdo->query(
        'SELECT module_id,enabled,license_status,expires_at FROM company_modules '
        . 'WHERE company_id=' . $companyId . ' ORDER BY module_id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $check($afterEntitlements === $beforeEntitlements, 'Selected module entitlements persist through the save');
} finally {
    $restore = $pdo->prepare(
        'INSERT INTO company_role_permissions(company_id,role_id,permission_id,granted_by) '
        . 'VALUES(:company,:role,:permission,:granted_by) '
        . 'ON DUPLICATE KEY UPDATE granted_by=VALUES(granted_by)'
    );
    $restore->execute([
        'company' => $companyId,
        'role' => (int) $grant['role_id'],
        'permission' => (int) $grant['permission_id'],
        'granted_by' => $grant['granted_by'] === null
            ? null
            : (int) $grant['granted_by'],
    ]);
}

echo sprintf("%d company-update checks, %d failures\n", $checks, $failures);
exit($failures === 0 ? 0 : 1);
