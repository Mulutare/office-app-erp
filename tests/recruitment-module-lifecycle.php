<?php

declare(strict_types=1);

require __DIR__ . '/../app/helpers/bootstrap.php';

use App\Models\Company;
use App\Repositories\MySql\CompanyMembershipRepository;
use App\Repositories\MySql\CompanyModuleRepository;
use App\Services\CompanyModuleService;
use App\Services\CompanyProvisioningService;

if (
    getenv('APP_ENV') !== 'testing'
    || getenv('DB_DATABASE') !== 'office_app_test'
) {
    throw new RuntimeException('Disposable recruitment database required.');
}

$pdo = db();
$checks = 0;
$failures = 0;

$check = static function (bool $ok, string $label) use (&$checks, &$failures): void {
    $checks++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};

$companyId = (int) $pdo->query(
    "SELECT company_id FROM companies WHERE code='default' LIMIT 1"
)->fetchColumn();

$actorId = (int) $pdo->query(
    "SELECT user_id FROM users
     WHERE username='test_platform_admin'
     LIMIT 1"
)->fetchColumn();

$recruitment = $pdo->query(
    "SELECT *
     FROM erp_modules
     WHERE code='recruitment'
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

$hr = $pdo->query(
    "SELECT *
     FROM erp_modules
     WHERE code='hr'
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

$check(
    $companyId > 0 && $actorId > 0,
    'Recruitment module lifecycle fixtures exist'
);

$check(
    is_array($recruitment)
    && ($recruitment['release_status'] ?? null) === 'released'
    && (int) ($recruitment['available'] ?? 0) === 1
    && ($recruitment['permission_namespace'] ?? null) === 'recruitment'
    && ($recruitment['route_path'] ?? null) === '/hr/recruitment'
    && ($recruitment['introduced_migration'] ?? null) === '110',
    'Recruitment is a released ERP module introduced by migration 110'
);

$dependency = $pdo->prepare(
    "SELECT COUNT(*)
     FROM erp_module_dependencies d
     INNER JOIN erp_modules child
        ON child.module_id=d.module_id
     INNER JOIN erp_modules required
        ON required.module_id=d.required_module_id
     WHERE child.code='recruitment'
       AND required.code='hr'
       AND d.dependency_type='required'"
);
$dependency->execute();

$check(
    (int) $dependency->fetchColumn() === 1,
    'Recruitment declares HR as a required module dependency'
);

$options = (new CompanyProvisioningService())->formOptions();
$option = null;

foreach ($options['modules'] as $candidate) {
    if (($candidate['code'] ?? null) === 'recruitment') {
        $option = $candidate;
        break;
    }
}

$check(
    is_array($option) && !empty($option['canLicense']),
    'Platform company provisioning exposes Recruitment as licensable'
);

$automaticEntitlement = $pdo->prepare(
    "SELECT COUNT(*)
     FROM company_modules cm
     INNER JOIN erp_modules m
        ON m.module_id=cm.module_id
     WHERE cm.company_id=?
       AND m.code='recruitment'"
);
$automaticEntitlement->execute([$companyId]);

$check(
    (int) $automaticEntitlement->fetchColumn() === 0,
    'Migration does not automatically license Recruitment to existing companies'
);

if (
    $companyId < 1
    || $actorId < 1
    || !is_array($recruitment)
    || !is_array($hr)
) {
    exit(1);
}

$pdo->beginTransaction();

try {
    $pdo->prepare(
        "UPDATE companies
         SET active=TRUE,
             approval_status='approved',
             subscription_status='active',
             subscription_expires_at=NULL
         WHERE company_id=?"
    )->execute([$companyId]);

    $upsert = $pdo->prepare(
        "INSERT INTO company_modules
            (
                company_id,
                module_id,
                enabled,
                license_status,
                licensed_at,
                expires_at,
                updated_by
            )
         VALUES(?,?,?, ?,NOW(),NULL,?)
         ON DUPLICATE KEY UPDATE
            enabled=VALUES(enabled),
            license_status=VALUES(license_status),
            licensed_at=VALUES(licensed_at),
            expires_at=NULL,
            updated_by=VALUES(updated_by)"
    );

    $upsert->execute([
        $companyId,
        (int) $hr['module_id'],
        1,
        'active',
        $actorId,
    ]);

    $upsert->execute([
        $companyId,
        (int) $recruitment['module_id'],
        0,
        'not_licensed',
        $actorId,
    ]);

    $modules = new CompanyModuleRepository();

    $effective = static function (
        CompanyModuleRepository $modules,
        int $companyId
    ): bool {
        return in_array(
            'recruitment',
            array_column(
                $modules->enabledForCompany($companyId),
                'code'
            ),
            true
        );
    };

    $check(
        !$effective($modules, $companyId),
        'Recruitment cannot become effective while not licensed'
    );

    $companies = new Company();
    $companies->updateModuleEntitlement(
        $companyId,
        (int) $recruitment['module_id'],
        true,
        'active',
        null,
        $actorId
    );

    $check(
        $effective($modules, $companyId),
        'Platform licensing mechanism can license Recruitment to a company'
    );

    $_SESSION['auth'] = [
        'user_id' => $actorId,
        'company' => ['company_id' => $companyId],
    ];

    $moduleService = new CompanyModuleService();

    $moduleService->setLicensedModuleEnabled(
        'recruitment',
        false,
        $actorId
    );

    $check(
        !$effective($modules, $companyId),
        'Licensed Recruitment can be switched OFF'
    );

    $moduleService->setLicensedModuleEnabled(
        'recruitment',
        true,
        $actorId
    );

    $check(
        $effective($modules, $companyId),
        'Licensed Recruitment can be switched ON'
    );

    $modules->setEnabled(
        $companyId,
        (int) $hr['module_id'],
        false,
        $actorId
    );

    $check(
        !$effective($modules, $companyId),
        'Recruitment fails closed when required HR module is disabled'
    );

    $modules->setEnabled(
        $companyId,
        (int) $hr['module_id'],
        true,
        $actorId
    );

    $check(
        $effective($modules, $companyId),
        'Recruitment becomes effective again when HR dependency is restored'
    );

    $pdo->prepare(
        "INSERT INTO roles(name,code)
         VALUES('Recruitment permission matrix','rec_module_matrix')"
    )->execute();

    $roleId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO users
            (
                username,
                email,
                password_hash,
                display_name,
                active,
                must_change_password
            )
         VALUES
            (
                'rec_module_matrix',
                'rec-module-matrix@example.test',
                'disabled-fixture-password',
                'Recruitment Matrix User',
                TRUE,
                FALSE
            )"
    )->execute();

    $userId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO company_users
            (company_id,user_id,active,is_default)
         VALUES(?,?,TRUE,TRUE)"
    )->execute([$companyId,$userId]);

    $pdo->prepare(
        "INSERT INTO company_user_roles
            (company_id,user_id,role_id)
         VALUES(?,?,?)"
    )->execute([$companyId,$userId,$roleId]);

    $viewPermission = (int) $pdo->query(
        "SELECT permission_id
         FROM permissions
         WHERE code='recruitment.view'"
    )->fetchColumn();

    $gatePermission = (int) $pdo->query(
        "SELECT permission_id
         FROM permissions
         WHERE code='recruitment.module.enabled'"
    )->fetchColumn();

    $grant = $pdo->prepare(
        "INSERT INTO company_role_permissions
            (company_id,role_id,permission_id,granted_by)
         VALUES(?,?,?,?)"
    );

    $grant->execute([
        $companyId,
        $roleId,
        $viewPermission,
        $actorId,
    ]);

    $memberships = new CompanyMembershipRepository();
    $permissions = $memberships->permissionCodes(
        $userId,
        $companyId
    );

    $check(
        !in_array('recruitment.view',$permissions,true),
        'Recruitment function permission cannot bypass its module gate'
    );

    $grant->execute([
        $companyId,
        $roleId,
        $gatePermission,
        $actorId,
    ]);

    $permissions = $memberships->permissionCodes(
        $userId,
        $companyId
    );

    $check(
        in_array('recruitment.module.enabled',$permissions,true)
        && in_array('recruitment.view',$permissions,true),
        'Recruitment uses the normal role plus module-gate permission mechanism'
    );

    $moduleService->setLicensedModuleEnabled(
        'recruitment',
        false,
        $actorId
    );

    $permissions = $memberships->permissionCodes(
        $userId,
        $companyId
    );

    $check(
        !in_array('recruitment.module.enabled',$permissions,true)
        && !in_array('recruitment.view',$permissions,true),
        'Disabling Recruitment removes its effective user permissions'
    );

    $companies->updateModuleEntitlement(
        $companyId,
        (int) $recruitment['module_id'],
        false,
        'active',
        null,
        $actorId
    );

    $check(
        $modules->licensedForCompany(
            $companyId,
            'recruitment'
        ) === null,
        'Platform licensing mechanism can remove the Recruitment license'
    );
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "$checks recruitment module lifecycle checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);