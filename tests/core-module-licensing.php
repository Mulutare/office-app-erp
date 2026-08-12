<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

use App\Models\CompanyModule;

$pdo = db();
$failures = 0;
$checks = 0;
$check = static function (bool $condition, string $description) use (&$failures, &$checks): void {
    $checks++;
    fwrite($condition ? STDOUT : STDERR, ($condition ? 'PASS ' : 'FAIL ') . $description . PHP_EOL);
    $failures += $condition ? 0 : 1;
};
$company = $pdo->query(
    "SELECT company_id FROM companies WHERE active=TRUE AND approval_status='approved' "
    . "AND subscription_status IN('active','trial') ORDER BY company_id LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$actor = $pdo->query('SELECT user_id FROM users WHERE active=TRUE ORDER BY user_id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$modules = $pdo->query(
    "SELECT module_id,code FROM erp_modules WHERE code IN('sales','inventory','finance') ORDER BY code"
)->fetchAll(PDO::FETCH_ASSOC);
$companyId = (int) ($company['company_id'] ?? 0);
$actorId = (int) ($actor['user_id'] ?? 0);
$check($companyId > 0 && $actorId > 0 && count($modules) === 3, 'Core-module licensing fixtures exist');
if ($companyId < 1 || $actorId < 1 || count($modules) !== 3) {
    exit(1);
}

$repository = new CompanyModule();
$available = static function (CompanyModule $repository, int $companyId, string $code): bool {
    return in_array($code, array_column($repository->enabledForCompany($companyId), 'code'), true);
};
$pdo->beginTransaction();
try {
    $setCompany = $pdo->prepare(
        "UPDATE companies SET active=TRUE,approval_status='approved',subscription_status='active',subscription_expires_at=NULL "
        . 'WHERE company_id=:company'
    );
    $setCompany->execute(['company' => $companyId]);
    $setModule = $pdo->prepare("UPDATE erp_modules SET active=TRUE,release_status='released' WHERE module_id=:module");
    $upsert = $pdo->prepare(
        'INSERT INTO company_modules(company_id,module_id,enabled,license_status,licensed_at,expires_at,updated_by) '
        . 'VALUES(:company,:module,:enabled,:license,NOW(),:expires,:actor) '
        . 'ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),license_status=VALUES(license_status),expires_at=VALUES(expires_at)'
    );

    foreach ($modules as $module) {
        $code = (string) $module['code'];
        $moduleId = (int) $module['module_id'];
        $setModule->execute(['module' => $moduleId]);

        $upsert->execute(['company' => $companyId, 'module' => $moduleId, 'enabled' => 1, 'license' => 'active', 'expires' => null, 'actor' => $actorId]);
        $check($available($repository, $companyId, $code), ucfirst($code) . ': licensed and enabled is effective');

        $upsert->execute(['company' => $companyId, 'module' => $moduleId, 'enabled' => 1, 'license' => 'not_licensed', 'expires' => null, 'actor' => $actorId]);
        $check(!$available($repository, $companyId, $code), ucfirst($code) . ': permission cannot bypass not licensed');

        $upsert->execute(['company' => $companyId, 'module' => $moduleId, 'enabled' => 0, 'license' => 'active', 'expires' => null, 'actor' => $actorId]);
        $check(!$available($repository, $companyId, $code), ucfirst($code) . ': licensed but disabled is ineffective');

        $upsert->execute(['company' => $companyId, 'module' => $moduleId, 'enabled' => 1, 'license' => 'active', 'expires' => '2000-01-01 00:00:00', 'actor' => $actorId]);
        $check(!$available($repository, $companyId, $code), ucfirst($code) . ': expired entitlement is ineffective');

        $pdo->prepare("UPDATE erp_modules SET release_status='roadmap' WHERE module_id=:module")
            ->execute(['module' => $moduleId]);
        $check(!$available($repository, $companyId, $code), ucfirst($code) . ': unreleased module is ineffective');

        $pdo->prepare('DELETE FROM company_modules WHERE company_id=:company AND module_id=:module')
            ->execute(['company' => $companyId, 'module' => $moduleId]);
        $check(!$available($repository, $companyId, $code), ucfirst($code) . ': missing entitlement is ineffective');

        $upsert->execute(['company' => $companyId, 'module' => $moduleId, 'enabled' => 1, 'license' => 'active', 'expires' => null, 'actor' => $actorId]);
        $setModule->execute(['module' => $moduleId]);
    }

    $pdo->prepare("UPDATE companies SET subscription_expires_at='2000-01-01 00:00:00' WHERE company_id=:company")
        ->execute(['company' => $companyId]);
    foreach ($modules as $module) {
        $check(!$available($repository, $companyId, (string) $module['code']), ucfirst((string) $module['code']) . ': expired company subscription is ineffective');
    }
} finally {
    $pdo->rollBack();
}

echo sprintf("%d core-module licensing checks, %d failures\n", $checks, $failures);
exit($failures === 0 ? 0 : 1);
