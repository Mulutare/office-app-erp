<?php

declare(strict_types=1);

use App\Database\MigrationRunner;
use App\Database\OracleDialect;

require_once __DIR__
    . '/../../app/helpers/bootstrap.php';

$results = [];
$failures = 0;

$check = static function (
    bool $condition,
    string $description
) use (&$results, &$failures): void {
    $results[] = [
        'passed' => $condition,
        'description' => $description,
    ];

    if (!$condition) {
        $failures++;
    }
};

$connection = db();

try {
    $check(
        databaseDriver()->name() === 'oracle',
        'Connection manager selected Oracle'
    );

    $check(
        databaseDriver()->dialect()
            instanceof OracleDialect,
        'Oracle connection exposes Oracle dialect'
    );

    $runner = new MigrationRunner(
        $connection,
        'oracle'
    );
    $migrationDirectory = __DIR__
        . '/../../database/migrations/oracle';
    $firstRun = $runner->run($migrationDirectory);
    $secondRun = $runner->run($migrationDirectory);
    $migrationCount = count(
        glob($migrationDirectory . '/*.php')
        ?: []
    );

    $check(
        count($firstRun['applied'])
            + count($firstRun['skipped'])
            === $migrationCount,
        'Every Oracle migration is accounted for'
    );

    $check(
        count($secondRun['applied']) === 0
        && count($secondRun['skipped'])
            === $migrationCount,
        'Applied migrations are not executed twice'
    );

    $tableCount = (int) $connection
        ->query(
            'SELECT COUNT(*)
             FROM user_tables
             WHERE table_name IN (
                 \'SCHEMA_MIGRATIONS\',
                 \'ROLES\',
                 \'PERMISSIONS\',
                 \'USERS\',
                 \'COMPANIES\',
                 \'USER_ROLES\',
                 \'ROLE_PERMISSIONS\',
                 \'COMPANY_USERS\',
                 \'COMPANY_USER_ROLES\',
                 \'COMPANY_ROLE_PERMISSIONS\',
                 \'ERP_MODULES\',
                 \'COMPANY_MODULES\',
                 \'HR_DEPARTMENTS\',
                 \'HR_EMPLOYEES\',
                 \'FINANCE_EXPENSE_CATEGORIES\',
                 \'FINANCE_EXPENSE_REQUESTS\',
                 \'ORGANIZATION_BRANCHES\',
                 \'ORGANIZATION_JOB_TITLES\',
                 \'ORGANIZATION_POSITIONS\',
                 \'LOGIN_ATTEMPTS\',
                 \'AUDIT_LOGS\'
             )'
        )
        ->fetchColumn();

    $check(
        $tableCount === 21,
        'Oracle schema contains the migration ledger and 20 application tables'
    );

    $foreignKeyCount = (int) $connection
        ->query(
            'SELECT COUNT(*)
             FROM user_constraints
             WHERE constraint_type = \'R\''
        )
        ->fetchColumn();

    $check(
        $foreignKeyCount === 56,
        'Oracle schema contains all 56 foreign keys'
    );

    $check(
        (int) $connection
            ->query('SELECT COUNT(*) FROM roles')
            ->fetchColumn() === 9,
        'Oracle role catalog matches MySQL'
    );

    $check(
        (int) $connection
            ->query('SELECT COUNT(*) FROM permissions')
            ->fetchColumn() === 23,
        'Oracle permission catalog matches MySQL'
    );

    $check(
        (int) $connection
            ->query('SELECT COUNT(*) FROM erp_modules')
            ->fetchColumn() === 12,
        'Oracle module catalog matches MySQL'
    );

    $testSuffix = strtolower(
        bin2hex(random_bytes(5))
    );

    $connection->beginTransaction();

    $roleCode = 'oracle_' . $testSuffix;
    $roleName = 'Oracle Unicode '
        . $testSuffix
        . ' – Nairobi 東京';
    $insertRole = $connection->prepare(
        'INSERT INTO roles
            (
                name,
                code,
                description
            )
         VALUES
            (
                :name,
                :code,
                :description
            )'
    );
    $insertRole->execute([
        'name' => $roleName,
        'code' => $roleCode,
        'description' =>
            'Unicode and generated identifier validation',
    ]);

    $findRole = $connection->prepare(
        'SELECT role_id, name
         FROM roles
         WHERE code = :code'
    );
    $findRole->execute([
        'code' => $roleCode,
    ]);
    $role = $findRole->fetch();

    $check(
        is_array($role)
        && (int) ($role['ROLE_ID']
            ?? $role['role_id']
            ?? 0) > 0,
        'Oracle identity column generates role identifiers'
    );

    $check(
        is_array($role)
        && ($role['NAME']
            ?? $role['name']
            ?? null) === $roleName,
        'Oracle stores and retrieves Unicode text'
    );

    $duplicateRejected = false;

    try {
        $insertRole->execute([
            'name' => 'Duplicate ' . $testSuffix,
            'code' => $roleCode,
            'description' =>
                'Unique constraint validation',
        ]);
    } catch (PDOException $exception) {
        $duplicateRejected = true;
    }

    $check(
        $duplicateRejected,
        'Oracle rejects duplicate role codes'
    );

    $companyInsert = $connection->prepare(
        'INSERT INTO companies
            (
                code,
                name
            )
         VALUES
            (
                :code,
                :name
            )'
    );
    $companyCodes = [
        'ora_a_' . $testSuffix,
        'ora_b_' . $testSuffix,
    ];

    foreach ($companyCodes as $companyCode) {
        $companyInsert->execute([
            'code' => $companyCode,
            'name' => 'Oracle Test ' . $companyCode,
        ]);
    }

    $companySelect = $connection->prepare(
        'SELECT company_id
         FROM companies
         WHERE code = :code'
    );
    $companyIds = [];

    foreach ($companyCodes as $companyCode) {
        $companySelect->execute([
            'code' => $companyCode,
        ]);
        $companyIds[] = (int) $companySelect
            ->fetchColumn();
    }

    $departmentInsert = $connection->prepare(
        'INSERT INTO hr_departments
            (
                company_id,
                code,
                name
            )
         VALUES
            (
                :company_id,
                :code,
                :name
            )'
    );

    foreach ($companyIds as $companyId) {
        $departmentInsert->execute([
            'company_id' => $companyId,
            'code' => 'OPS',
            'name' => 'Operations',
        ]);
    }

    $departmentIdSelect = $connection->prepare(
        'SELECT department_id
         FROM hr_departments
         WHERE company_id = :company_id
           AND code = :code'
    );
    $departmentIds = [];

    foreach ($companyIds as $companyId) {
        $departmentIdSelect->execute([
            'company_id' => $companyId,
            'code' => 'OPS',
        ]);
        $departmentIds[] = (int) (
            $departmentIdSelect->fetchColumn()
        );
    }

    $crossTenantHierarchyRejected = false;

    try {
        $crossTenantChild = $connection->prepare(
            'INSERT INTO hr_departments
                (
                    company_id,
                    code,
                    name,
                    parent_department_id
                )
             VALUES
                (
                    :company_id,
                    :code,
                    :name,
                    :parent_department_id
                )'
        );
        $crossTenantChild->execute([
            'company_id' => $companyIds[0],
            'code' => 'CROSS-PARENT',
            'name' => 'Cross Tenant Parent',
            'parent_department_id' =>
                $departmentIds[1],
        ]);
    } catch (\PDOException $exception) {
        $crossTenantHierarchyRejected = true;
    }

    $check(
        $crossTenantHierarchyRejected,
        'Oracle department hierarchy rejects cross-tenant parents'
    );

    $tenantCount = $connection->prepare(
        'SELECT COUNT(*)
         FROM hr_departments
         WHERE company_id = :company_id
           AND code = :code'
    );
    $tenantCount->execute([
        'company_id' => $companyIds[0],
        'code' => 'OPS',
    ]);

    $check(
        (int) $tenantCount->fetchColumn() === 1,
        'Tenant-scoped query isolates identical department codes'
    );

    $branchInsert = $connection->prepare(
        'INSERT INTO organization_branches
            (
                company_id,
                code,
                name
            )
         VALUES
            (
                :company_id,
                :code,
                :name
            )'
    );
    $jobTitleInsert = $connection->prepare(
        'INSERT INTO organization_job_titles
            (
                company_id,
                code,
                name
            )
         VALUES
            (
                :company_id,
                :code,
                :name
            )'
    );

    foreach ($companyIds as $companyId) {
        $branchInsert->execute([
            'company_id' => $companyId,
            'code' => 'HQ',
            'name' => 'Head Office',
        ]);
        $jobTitleInsert->execute([
            'company_id' => $companyId,
            'code' => 'ANL',
            'name' => 'Analyst',
        ]);
    }

    $branchIds = [];
    $jobTitleIds = [];

    foreach ($companyIds as $companyId) {
        $branchSelect = $connection->prepare(
            'SELECT branch_id
             FROM organization_branches
             WHERE company_id = :company_id
               AND code = :code'
        );
        $branchSelect->execute([
            'company_id' => $companyId,
            'code' => 'HQ',
        ]);
        $branchIds[] = (int) (
            $branchSelect->fetchColumn()
        );
        $jobTitleSelect = $connection->prepare(
            'SELECT job_title_id
             FROM organization_job_titles
             WHERE company_id = :company_id
               AND code = :code'
        );
        $jobTitleSelect->execute([
            'company_id' => $companyId,
            'code' => 'ANL',
        ]);
        $jobTitleIds[] = (int) (
            $jobTitleSelect->fetchColumn()
        );
    }

    $positionInsert = $connection->prepare(
        'INSERT INTO organization_positions
            (
                company_id,
                code,
                name,
                branch_id,
                department_id,
                job_title_id,
                approved_headcount,
                status
            )
         VALUES
            (
                :company_id,
                :code,
                :name,
                :branch_id,
                :department_id,
                :job_title_id,
                :approved_headcount,
                :status
            )'
    );

    foreach ($companyIds as $index => $companyId) {
        $positionInsert->execute([
            'company_id' => $companyId,
            'code' => 'OPS-ANL',
            'name' => 'Operations Analyst',
            'branch_id' => $branchIds[$index],
            'department_id' =>
                $departmentIds[$index],
            'job_title_id' =>
                $jobTitleIds[$index],
            'approved_headcount' => 2,
            'status' => 'open',
        ]);
    }

    $positionCount = $connection->prepare(
        'SELECT COUNT(*)
         FROM organization_positions
         WHERE company_id = :company_id
           AND code = :code'
    );
    $positionCount->execute([
        'company_id' => $companyIds[0],
        'code' => 'OPS-ANL',
    ]);
    $check(
        (int) $positionCount->fetchColumn() === 1,
        'Oracle positions are tenant scoped'
    );

    $crossTenantPositionRejected = false;

    try {
        $positionInsert->execute([
            'company_id' => $companyIds[0],
            'code' => 'CROSS-POS',
            'name' => 'Cross Tenant Position',
            'branch_id' => $branchIds[1],
            'department_id' => $departmentIds[0],
            'job_title_id' => $jobTitleIds[0],
            'approved_headcount' => 1,
            'status' => 'planned',
        ]);
    } catch (\PDOException $exception) {
        $crossTenantPositionRejected = true;
    }

    $check(
        $crossTenantPositionRejected,
        'Oracle position foreign keys reject cross-tenant organization references'
    );

    $longJson = json_encode(
        [
            'message' => str_repeat(
                'Oracle CLOB validation ',
                300
            ),
        ],
        JSON_THROW_ON_ERROR
    );
    $auditInsert = $connection->prepare(
        'INSERT INTO audit_logs
            (
                company_id,
                action,
                module,
                new_values
            )
         VALUES
            (
                :company_id,
                :action,
                :module,
                :new_values
            )'
    );
    $auditInsert->execute([
        'company_id' => $companyIds[0],
        'action' => 'ORACLE_TEST',
        'module' => 'testing',
        'new_values' => $longJson,
    ]);

    $clobLength = $connection->prepare(
        'SELECT DBMS_LOB.GETLENGTH(new_values)
         FROM audit_logs
         WHERE company_id = :company_id
           AND action = :action'
    );
    $clobLength->execute([
        'company_id' => $companyIds[0],
        'action' => 'ORACLE_TEST',
    ]);

    $check(
        (int) $clobLength->fetchColumn()
            === strlen($longJson),
        'Oracle CLOB preserves long audit JSON'
    );

    $pagination = $connection->query(
        'SELECT code
         FROM roles
         ORDER BY code
         OFFSET 1 ROWS FETCH NEXT 2 ROWS ONLY'
    )->fetchAll();

    $check(
        count($pagination) === 2,
        'Oracle pagination returns the requested page size'
    );

    $connection->rollBack();

    $findRole->execute([
        'code' => $roleCode,
    ]);

    $check(
        $findRole->fetchColumn() === false,
        'Oracle transaction rollback removes test writes'
    );
} catch (Throwable $exception) {
    $failures++;
    $results[] = [
        'passed' => false,
        'description' =>
            'Unexpected Oracle integration exception: '
            . $exception->getMessage(),
    ];

    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
}

foreach ($results as $result) {
    fwrite(
        STDOUT,
        sprintf(
            '%s %s%s',
            $result['passed'] ? '[PASS]' : '[FAIL]',
            $result['description'],
            PHP_EOL
        )
    );
}

fwrite(
    STDOUT,
    sprintf(
        '%d checks, %d failures.%s',
        count($results),
        $failures,
        PHP_EOL
    )
);

exit($failures === 0 ? 0 : 1);
