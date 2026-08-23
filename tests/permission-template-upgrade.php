<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

use App\Repositories\MySql\CompanyMembershipRepository;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $description) use (
    &$passed,
    &$failed
): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $description . PHP_EOL;
    $condition ? $passed++ : $failed++;
};
$connection = db();
$permissionCodes = [
    'sales.settlements.view',
    'sales.settlements.create',
    'sales.settlements.submit',
    'sales.settlements.review',
    'finance.settlements.view',
    'finance.settlements.reconcile',
    'finance.settlements.approve',
    'finance.bank_confirmations.create',
    'finance.bank_accounts.manage',
    'commercial_documents.download',
    'company.document_branding.manage',
    'analytics.view',
    'analytics.configure',
];
$quotedCodes = implode(
    ',',
    array_map(
        static fn (string $code): string => $connection->quote($code),
        $permissionCodes
    )
);
$company = $connection->query(
    "SELECT companies.company_id, assignments.user_id
     FROM companies
     INNER JOIN company_user_roles assignments
        ON assignments.company_id = companies.company_id
     INNER JOIN roles
        ON roles.role_id = assignments.role_id
       AND roles.code = 'company_owner'
     WHERE companies.code = 'sample-company'
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$companyId = (int) ($company['company_id'] ?? 0);
$ownerUserId = (int) ($company['user_id'] ?? 0);
$otherCompanyId = (int) $connection->query(
    'SELECT company_id
     FROM companies
     WHERE company_id <> ' . $companyId . '
     ORDER BY company_id
     LIMIT 1'
)->fetchColumn();
$migration = require dirname(__DIR__)
    . '/database/migrations/mysql/056_propagate_settlement_analytics_permissions.php';
$recovery = require dirname(__DIR__)
    . '/database/migrations/mysql/recovery/056.php';
$recoverySource = (string) file_get_contents(
    dirname(__DIR__) . '/database/migrations/mysql/recovery/056.php'
);
$upgradeSql = (string) ($migration['statements'][0] ?? '');
$targetBefore = [];
$unrelatedBefore = [];
$removedUnrelated = null;

$targetRows = static function (int $tenantId) use (
    $connection,
    $quotedCodes
): array {
    $statement = $connection->query(
        'SELECT grants.role_id, grants.permission_id, grants.granted_by
         FROM company_role_permissions grants
         INNER JOIN permissions
            ON permissions.permission_id = grants.permission_id
         WHERE grants.company_id = ' . $tenantId . '
           AND permissions.code IN (' . $quotedCodes . ')
         ORDER BY grants.role_id, grants.permission_id'
    );

    return $statement->fetchAll(PDO::FETCH_ASSOC);
};
$unrelatedRows = static function (int $tenantId) use (
    $connection,
    $quotedCodes
): array {
    return $connection->query(
        'SELECT grants.role_id, grants.permission_id, grants.granted_by
         FROM company_role_permissions grants
         INNER JOIN permissions
            ON permissions.permission_id = grants.permission_id
         WHERE grants.company_id = ' . $tenantId . '
           AND permissions.code NOT IN (' . $quotedCodes . ')
         ORDER BY grants.role_id, grants.permission_id'
    )->fetchAll(PDO::FETCH_ASSOC);
};

try {
    $check(
        $companyId > 0 && $ownerUserId > 0 && $otherCompanyId > 0,
        'Permission-upgrade tenant fixtures exist'
    );
    $check(
        count($permissionCodes) === 13
        && array_reduce(
            $permissionCodes,
            static fn (bool $valid, string $code): bool =>
                $valid && substr_count($recoverySource, "'$code'") === 1,
            true
        ),
        'Recovery uses one complete canonical list of all 13 permission codes'
    );

    if ($companyId < 1 || $ownerUserId < 1 || $otherCompanyId < 1) {
        throw new RuntimeException('Permission-upgrade fixtures are incomplete.');
    }

    $targetBefore = $targetRows($companyId);
    $unrelatedBefore = $unrelatedRows($companyId);
    $otherCompanyBefore = $targetRows($otherCompanyId);
    $removedUnrelated = $connection->query(
        'SELECT grants.role_id, grants.permission_id, grants.granted_by
         FROM company_role_permissions grants
         INNER JOIN role_permissions templates
            ON templates.role_id = grants.role_id
           AND templates.permission_id = grants.permission_id
         INNER JOIN permissions
            ON permissions.permission_id = grants.permission_id
         WHERE grants.company_id = ' . $companyId . '
           AND permissions.code NOT IN (' . $quotedCodes . ')
         ORDER BY grants.role_id, grants.permission_id
         LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);

    if (!is_array($removedUnrelated)) {
        throw new RuntimeException('No unrelated permission customization fixture exists.');
    }

    $connection->prepare(
        'DELETE FROM company_role_permissions
         WHERE company_id = :company
           AND role_id = :role
           AND permission_id = :permission'
    )->execute([
        'company' => $companyId,
        'role' => (int) $removedUnrelated['role_id'],
        'permission' => (int) $removedUnrelated['permission_id'],
    ]);
    $connection->exec(
        'DELETE grants
         FROM company_role_permissions grants
         INNER JOIN permissions
            ON permissions.permission_id = grants.permission_id
         WHERE grants.company_id = ' . $companyId . '
           AND permissions.code IN (' . $quotedCodes . ')'
    );
    $check(
        $recovery($connection) === 'apply',
        'Recovery selects fresh apply when intended grants are missing'
    );

    $connection->exec($upgradeSql);
    $targetAfter = $targetRows($companyId);
    $expectedTemplates = $connection->query(
        'SELECT templates.role_id, templates.permission_id
         FROM role_permissions templates
         INNER JOIN permissions
            ON permissions.permission_id = templates.permission_id
         WHERE permissions.code IN (' . $quotedCodes . ')
           AND permissions.active = TRUE
         ORDER BY templates.role_id, templates.permission_id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $actualPairs = array_map(
        static fn (array $row): array => [
            'role_id' => $row['role_id'],
            'permission_id' => $row['permission_id'],
        ],
        $targetAfter
    );
    $check(
        $actualPairs === $expectedTemplates,
        'Existing company receives exactly the new global role templates'
    );

    $permissions = (new CompanyMembershipRepository())->permissionCodes(
        $ownerUserId,
        $companyId
    );
    $check(
        in_array('analytics.view', $permissions, true),
        'Entitled company owner receives effective analytics.view'
    );
    $check(
        in_array('sales.settlements.view', $permissions, true),
        'Entitled company owner receives effective sales.settlements.view'
    );

    $roleMatrix = $connection->query(
        'SELECT roles.code AS role_code, permissions.code AS permission_code
         FROM company_role_permissions grants
         INNER JOIN roles ON roles.role_id = grants.role_id
         INNER JOIN permissions ON permissions.permission_id = grants.permission_id
         WHERE grants.company_id = ' . $companyId . '
           AND permissions.code IN (' . $quotedCodes . ')
         ORDER BY roles.code, permissions.code'
    )->fetchAll(PDO::FETCH_ASSOC);
    $templateMatrix = $connection->query(
        'SELECT roles.code AS role_code, permissions.code AS permission_code
         FROM role_permissions templates
         INNER JOIN roles ON roles.role_id = templates.role_id
         INNER JOIN permissions ON permissions.permission_id = templates.permission_id
         WHERE permissions.code IN (' . $quotedCodes . ')
         ORDER BY roles.code, permissions.code'
    )->fetchAll(PDO::FETCH_ASSOC);
    $check(
        $roleMatrix === $templateMatrix,
        'Sales and Finance roles receive only their intended new permissions'
    );

    $expectedUnrelated = array_values(array_filter(
        $unrelatedBefore,
        static fn (array $row): bool => !(
            (int) $row['role_id'] === (int) $removedUnrelated['role_id']
            && (int) $row['permission_id'] === (int) $removedUnrelated['permission_id']
        )
    ));
    $check(
        $unrelatedRows($companyId) === $expectedUnrelated,
        'Unrelated tenant permission customizations remain unchanged'
    );
    $check(
        $targetRows($otherCompanyId) === $otherCompanyBefore,
        'Permission propagation preserves other-tenant rows and attribution'
    );

    $connection->exec($upgradeSql);
    $check(
        $targetRows($companyId) === $targetAfter
        && $recovery($connection) === 'baseline',
        'Permission propagation and clean-schema recovery are idempotent'
    );
    $migrationChecksum = (string) $connection->query(
        "SELECT checksum FROM schema_migrations WHERE version = '056'"
    )->fetchColumn();
    $connection->prepare(
        'INSERT INTO schema_migration_steps
            (version, statement_number, migration_checksum, statement_checksum)
         VALUES (:version, 1, :migration_checksum, :statement_checksum)'
    )->execute([
        'version' => '056',
        'migration_checksum' => $migrationChecksum,
        'statement_checksum' => hash('sha256', trim($upgradeSql)),
    ]);
    $check(
        $recovery($connection) === 'apply',
        'Recovery resumes a valid completed migration-056 statement'
    );
    $connection->prepare(
        'DELETE FROM schema_migration_steps WHERE version = :version'
    )->execute(['version' => '056']);
} catch (Throwable $exception) {
    echo 'FAIL unexpected: ' . $exception->getMessage() . PHP_EOL;
    $failed++;
} finally {
    $connection->prepare(
        'DELETE FROM schema_migration_steps WHERE version = :version'
    )->execute(['version' => '056']);

    if ($companyId > 0) {
        $connection->exec(
            'DELETE grants
             FROM company_role_permissions grants
             INNER JOIN permissions
                ON permissions.permission_id = grants.permission_id
             WHERE grants.company_id = ' . $companyId . '
               AND permissions.code IN (' . $quotedCodes . ')'
        );
        $restore = $connection->prepare(
            'INSERT INTO company_role_permissions
                (company_id, role_id, permission_id, granted_by)
             VALUES (:company, :role, :permission, :granted_by)'
        );

        foreach ($targetBefore as $row) {
            $restore->execute([
                'company' => $companyId,
                'role' => (int) $row['role_id'],
                'permission' => (int) $row['permission_id'],
                'granted_by' => $row['granted_by'],
            ]);
        }

        if (is_array($removedUnrelated)) {
            $restore->execute([
                'company' => $companyId,
                'role' => (int) $removedUnrelated['role_id'],
                'permission' => (int) $removedUnrelated['permission_id'],
                'granted_by' => $removedUnrelated['granted_by'],
            ]);
        }
    }
}

echo PHP_EOL . ($passed + $failed)
    . ' permission-upgrade checks, '
    . $failed
    . ' failures'
    . PHP_EOL;

exit($failed === 0 ? 0 : 1);
