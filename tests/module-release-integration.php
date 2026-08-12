<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

use App\Repositories\MySql\CompanyModuleRepository;
use App\Services\CompanyModuleService;

$failures = 0;
$checks = 0;
$check = static function (bool $condition, string $description) use (&$failures, &$checks): void {
    $checks++;
    fwrite($condition ? STDOUT : STDERR, ($condition ? 'PASS ' : 'FAIL ') . $description . PHP_EOL);
    if (!$condition) {
        $failures++;
    }
};

$pdo = db();
$company = $pdo->query(
    "SELECT company_id FROM companies
     WHERE code='default' AND active=TRUE AND approval_status='approved' LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$actor = $pdo->query("SELECT user_id FROM users WHERE username='test_platform_admin' LIMIT 1")
    ->fetch(PDO::FETCH_ASSOC);
$moduleRows = $pdo->query(
    "SELECT module_id,code,name,route_path,release_status,first_release_version,introduced_migration
     FROM erp_modules WHERE code IN('assets','finance','projects','it_assets')"
)->fetchAll(PDO::FETCH_ASSOC);
$modules = [];
foreach ($moduleRows as $row) {
    $modules[(string) $row['code']] = $row;
}

$companyId = (int) ($company['company_id'] ?? 0);
$actorId = (int) ($actor['user_id'] ?? 0);
$check($companyId > 0 && $actorId > 0, 'Module lifecycle test actors exist');
$check(
    ($modules['assets']['release_status'] ?? null) === 'released'
    && ($modules['assets']['route_path'] ?? null) === '/assets'
    && ($modules['assets']['introduced_migration'] ?? null) === '046'
    && array_key_exists('first_release_version', $modules['assets'])
    && $modules['assets']['first_release_version'] === null,
    'Fixed Assets release state is explicit, traceable to migration 046, and has no invented release version'
);
$check(
    ($modules['it_assets']['release_status'] ?? null) === 'roadmap'
    && ($modules['it_assets']['route_path'] ?? null) === '/it-assets'
    && ($modules['assets']['module_id'] ?? null) !== ($modules['it_assets']['module_id'] ?? null),
    'Accounting Assets remains distinct from roadmap IT Assets'
);

if ($companyId <= 0 || $actorId <= 0 || !isset($modules['assets'], $modules['finance'], $modules['projects'])) {
    fwrite(STDERR, "FAIL Module lifecycle fixtures unavailable.\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    $upsert = $pdo->prepare(
        "INSERT INTO company_modules(company_id,module_id,enabled,license_status,licensed_at,expires_at,updated_by)
         VALUES(:company,:module,:enabled,:license,NOW(),NULL,:actor)
         ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),license_status=VALUES(license_status),
             expires_at=NULL,updated_by=VALUES(updated_by)"
    );
    $setModuleRelease = $pdo->prepare('UPDATE erp_modules SET release_status=:status WHERE module_id=:module');
    $repository = new CompanyModuleRepository();
    $available = static function (CompanyModuleRepository $repository, int $companyId, string $code): bool {
        foreach ($repository->enabledForCompany($companyId) as $module) {
            if (($module['code'] ?? null) === $code) {
                return true;
            }
        }
        return false;
    };
    $entitle = static function (array $module, string $license, bool $enabled) use ($upsert, $companyId, $actorId): void {
        $upsert->execute([
            'company' => $companyId,
            'module' => (int) $module['module_id'],
            'enabled' => $enabled ? 1 : 0,
            'license' => $license,
            'actor' => $actorId,
        ]);
    };

    // Satisfy the required Finance dependency while testing the Assets truth table.
    $setModuleRelease->execute(['status' => 'released', 'module' => (int) $modules['finance']['module_id']]);
    $entitle($modules['finance'], 'active', true);

    $matrix = [
        ['roadmap', 'not_licensed', false, false],
        ['roadmap', 'active', false, false],
        ['roadmap', 'active', true, false],
        ['released', 'not_licensed', false, false],
        ['released', 'not_licensed', true, false],
        ['released', 'active', false, false],
        ['released', 'active', true, true],
    ];
    foreach ($matrix as [$release, $license, $enabled, $expected]) {
        $setModuleRelease->execute(['status' => $release, 'module' => (int) $modules['assets']['module_id']]);
        $entitle($modules['assets'], $license, $enabled);
        $check(
            $available($repository, $companyId, 'assets') === $expected,
            sprintf('State matrix: %s / %s / %s is %s', $release, $license, $enabled ? 'enabled' : 'disabled', $expected ? 'available' : 'unavailable')
        );
    }

    $entitle($modules['finance'], 'not_licensed', false);
    $check(!$available($repository, $companyId, 'assets'), 'Assets availability fails closed when required Finance is unavailable');

    $setModuleRelease->execute(['status' => 'roadmap', 'module' => (int) $modules['projects']['module_id']]);
    $entitle($modules['projects'], 'active', true);
    $check(!$available($repository, $companyId, 'projects'), 'Roadmap module remains unavailable despite a forged license and enabled row');

    $_SESSION['auth'] = [
        'user_id' => $actorId,
        'company' => ['company_id' => $companyId],
    ];
    $service = new CompanyModuleService();
    $roadmapMutation = $service->updateEnabledModules(['projects'], $actorId);
    $check(($roadmapMutation['successful'] ?? true) === false, 'Direct enable mutation rejects a roadmap module');

    $setModuleRelease->execute(['status' => 'released', 'module' => (int) $modules['assets']['module_id']]);
    $entitle($modules['assets'], 'active', false);
    try {
        $dependencyMutation = $service->updateEnabledModules(['assets'], $actorId);
        $check(($dependencyMutation['successful'] ?? true) === false, 'Direct enable mutation rejects Assets without required Finance selection');
    } catch (Throwable $exception) {
        fwrite(STDERR, sprintf("Dependency mutation exception: %s: %s at %s:%d\n", $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
        $check(false, 'Direct enable mutation rejects Assets without required Finance selection');
    }

    $contradictions = (int) $pdo->query(
        "SELECT COUNT(*) FROM company_modules cm
         INNER JOIN erp_modules m ON m.module_id=cm.module_id
         WHERE cm.enabled=TRUE AND (m.release_status<>'released' OR cm.license_status NOT IN('active','trial'))"
    )->fetchColumn();
    $duplicates = (int) $pdo->query(
        "SELECT COUNT(*) FROM (
            SELECT code FROM erp_modules GROUP BY code HAVING COUNT(*)>1
            UNION ALL
            SELECT route_path FROM erp_modules WHERE route_path IS NOT NULL AND route_path<>''
            GROUP BY route_path HAVING COUNT(*)>1
         ) conflicts"
    )->fetchColumn();
    $check($contradictions >= 1, 'Catalog consistency detector finds deliberately forged lifecycle contradictions');
    $check($duplicates === 0, 'Module catalog has unique codes and operational routes');
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo sprintf("%d module lifecycle checks, %d failures\n", $checks, $failures);
exit($failures === 0 ? 0 : 1);
