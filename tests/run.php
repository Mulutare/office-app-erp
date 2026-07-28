<?php

declare(strict_types=1);

require_once __DIR__
    . '/../app/helpers/bootstrap.php';

use App\Database\ConnectionManager;
use App\Database\MigrationRunner;
use App\Database\MySqlDialect;
use App\Database\OracleDialect;
use App\Database\OracleDriver;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Repositories\MySql\CompanyMembershipRepository;
use App\Repositories\MySql\DashboardStatisticsRepository;
use App\Repositories\Oracle\DashboardStatisticsRepository
    as OracleDashboardStatisticsRepository;
use App\Repositories\RepositoryFactory;
use App\Services\AuthService;
use App\Services\AttendanceManagementService;
use App\Services\BranchManagementService;
use App\Services\CompanyModuleService;
use App\Services\CompanyLifecycleService;
use App\Services\CompanyProvisioningService;
use App\Services\CompanyUpdateService;
use App\Services\DashboardService;
use App\Services\DepartmentCatalogueService;
use App\Services\EmployeeActivityService;
use App\Services\EmployeeDirectoryService;
use App\Services\EmployeePositionAssignmentService;
use App\Services\EmployeeUpdateService;
use App\Services\FinanceDashboardService;
use App\Services\JobTitleManagementService;
use App\Services\LeaveManagementService;
use App\Services\PlatformAdministratorProtectionService;
use App\Services\PositionManagementService;
use App\Services\RolePermissionUpdateService;
use App\Services\UserAccountStatusService;
use App\Services\UserAccountUnlockService;
use App\Services\UserAdministrationService;
use App\Services\UserCreationService;
use App\Services\UserDetailsService;
use App\Services\UserPasswordResetService;
use App\Services\UserUpdateService;

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

try {
    $runtimeRequirements = require __DIR__
        . '/../config/runtime.php';
    $minimumPhpVersionId = (int) (
        $runtimeRequirements[
            'minimum_php_version_id'
        ] ?? 80400
    );

    $check(
        PHP_VERSION_ID >= $minimumPhpVersionId,
        'Runtime uses PHP 8.4 or newer'
    );

    $check(
        config('environment') === 'testing',
        'Application environment is testing'
    );

    $check(
        config('debug') === false,
        'Debug output is disabled during integration tests'
    );

    $requiredExtensions = is_array(
        $runtimeRequirements[
            'required_extensions'
        ] ?? null
    )
        ? $runtimeRequirements[
            'required_extensions'
        ]
        : [];
    $driverExtensions = is_array(
        $runtimeRequirements[
            'database_driver_extensions'
        ] ?? null
    )
        ? $runtimeRequirements[
            'database_driver_extensions'
        ]
        : [];
    $requiredExtensions = array_values(
        array_unique(
            array_merge(
                $requiredExtensions,
                is_array(
                    $driverExtensions['mysql']
                    ?? null
                )
                    ? $driverExtensions['mysql']
                    : []
            )
        )
    );

    foreach ($requiredExtensions as $extension) {
        if (!is_string($extension)) {
            continue;
        }

        $check(
            extension_loaded($extension),
            'Required extension is loaded: ' . $extension
        );
    }

    $databaseVersion = (string) db()
        ->query('SELECT VERSION()')
        ->fetchColumn();

    $check(
        stripos($databaseVersion, 'MariaDB') !== false,
        'Database server is MariaDB'
    );

    $check(
        databaseDriver()->name() === 'mysql',
        'Connection manager selected the MySQL driver'
    );

    $dialect = databaseDriver()->dialect();

    $check(
        $dialect instanceof MySqlDialect
        && $dialect->name() === 'mysql',
        'Connection driver exposes the MySQL dialect'
    );

    $invalidIdentifierRejected = false;

    try {
        $dialect->todayRangePredicate(
            'attempted_at; DROP TABLE users'
        );
    } catch (InvalidArgumentException $exception) {
        $invalidIdentifierRejected = true;
    }

    $check(
        $invalidIdentifierRejected,
        'SQL dialect rejects unsafe identifier fragments'
    );

    $check(
        RepositoryFactory::dashboardStatistics()
            instanceof DashboardStatisticsRepository,
        'Repository factory selects the MySQL dashboard repository'
    );

    $oracleManager = ConnectionManager::fromConfig([
        'driver' => 'oracle',
    ]);
    $oracleDriver = $oracleManager->driver();
    $oracleDialect = $oracleDriver->dialect();

    $check(
        $oracleDriver instanceof OracleDriver,
        'Connection manager allowlists the Oracle adapter skeleton'
    );

    $check(
        $oracleDialect instanceof OracleDialect
        && $oracleDialect->paginationClause()
            === 'OFFSET :offset ROWS'
                . ' FETCH NEXT :limit ROWS ONLY',
        'Oracle dialect exposes Oracle pagination syntax'
    );

    $check(
        !extension_loaded('pdo_oci'),
        'Standard MySQL image excludes optional Oracle libraries'
    );

    $oracleRepositoryFailsClosed = false;

    try {
        (new OracleDashboardStatisticsRepository())
            ->statistics(1);
    } catch (LogicException $exception) {
        $oracleRepositoryFailsClosed =
            $exception->getMessage()
            === 'The Oracle dashboard repository is not implemented.';
    }

    $check(
        $oracleRepositoryFailsClosed,
        'Unverified Oracle repository skeleton fails closed'
    );

    $oracleMigrationFiles = glob(
        __DIR__
        . '/../database/migrations/oracle/*.php'
    );
    $oracleMigrationFiles = is_array(
        $oracleMigrationFiles
    )
        ? $oracleMigrationFiles
        : [];
    sort($oracleMigrationFiles, SORT_STRING);

    $oracleMigrationVersions = [];
    $oracleMigrationSql = [];
    $oracleMigrationDefinitionsValid = true;

    foreach ($oracleMigrationFiles as $migrationFile) {
        $definition = require $migrationFile;

        if (
            !is_array($definition)
            || !is_string(
                $definition['version'] ?? null
            )
            || !is_string(
                $definition['description'] ?? null
            )
            || !is_array(
                $definition['statements'] ?? null
            )
        ) {
            $oracleMigrationDefinitionsValid = false;

            continue;
        }

        $oracleMigrationVersions[] =
            $definition['version'];

        foreach (
            $definition['statements']
            as $migrationStatement
        ) {
            if (!is_string($migrationStatement)) {
                $oracleMigrationDefinitionsValid = false;

                continue;
            }

            $oracleMigrationSql[] =
                $migrationStatement;
        }
    }

    $check(
        class_exists(MigrationRunner::class)
        && $oracleMigrationDefinitionsValid
        && count($oracleMigrationFiles) === 13,
        'Oracle migration catalog contains thirteen valid definitions'
    );

    $check(
        $oracleMigrationVersions
            === array_values(
                array_unique(
                    $oracleMigrationVersions
                )
            )
        && $oracleMigrationVersions
            === [
                '010',
                '020',
                '030',
                '040',
                '050',
                '060',
                '070',
                '080',
                '090',
                '100',
                '110',
                '120',
                '130',
            ],
        'Oracle migration versions are unique and ordered'
    );

    $oracleCombinedSql = implode(
        PHP_EOL,
        $oracleMigrationSql
    );
    $oracleTableCount = preg_match_all(
        '/\bCREATE\s+TABLE\s+[A-Za-z_][A-Za-z0-9_]*/i',
        $oracleCombinedSql
    );
    $oracleIdentityCount = preg_match_all(
        '/GENERATED\s+BY\s+DEFAULT\s+ON\s+NULL\s+AS\s+IDENTITY/i',
        $oracleCombinedSql
    );

    $check(
        $oracleTableCount === 24
        && $oracleIdentityCount === 18,
        'Oracle migrations define all tables and generated identifiers'
    );

    $check(
        preg_match(
            '/AUTO_INCREMENT|TINYINT|ENGINE\s*=|'
            . 'ON\s+UPDATE\s+CURRENT_TIMESTAMP|'
            . '\bLIMIT\b|`/i',
            $oracleCombinedSql
        ) !== 1,
        'Oracle migrations exclude MySQL-only SQL constructs'
    );

    $check(
        str_contains(
            $oracleCombinedSql,
            'NUMBER(1)'
        )
        && str_contains(
            $oracleCombinedSql,
            'VARCHAR2('
        )
        && str_contains(
            $oracleCombinedSql,
            ' CLOB'
        )
        && str_contains(
            $oracleCombinedSql,
            'SYSTIMESTAMP'
        ),
        'Oracle migrations use Oracle-native flags, text and timestamps'
    );

    $check(
        is_file(
            __DIR__ . '/oracle/run.php'
        ),
        'Optional Oracle integration test entry point exists'
    );

    $check(
        is_subclass_of(
            CompanyMembership::class,
            CompanyMembershipRepository::class
        ),
        'Legacy model API delegates to the MySQL repository'
    );

    $unsupportedDriverRejected = false;

    try {
        ConnectionManager::fromConfig([
            'driver' => 'unsupported',
        ]);
    } catch (RuntimeException $exception) {
        $unsupportedDriverRejected =
            $exception->getMessage()
            === 'The configured database driver is not available.';
    }

    $check(
        $unsupportedDriverRejected,
        'Connection manager rejects unavailable database drivers'
    );

    $tableCount = (int) db()
        ->query(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_type = \'BASE TABLE\''
        )
        ->fetchColumn();

    $check(
        $tableCount === 24,
        'All 24 application tables were created'
    );

    $foreignKeyCount = (int) db()
        ->query(
            'SELECT COUNT(*)
             FROM information_schema.referential_constraints
             WHERE constraint_schema = DATABASE()'
        )
        ->fetchColumn();

    $check(
        $foreignKeyCount === 75,
        'All 75 foreign-key relationships were created'
    );

    $csrfToken = csrfToken();

    $check(
        verifyCsrfToken($csrfToken),
        'Valid CSRF tokens are accepted'
    );

    $check(
        !verifyCsrfToken(str_repeat('0', 64)),
        'Invalid CSRF tokens are rejected'
    );

    $username = getenv('TEST_ADMIN_USERNAME');
    $password = getenv('TEST_ADMIN_PASSWORD');

    $check(
        is_string($username) && $username !== '',
        'Test administrator username is configured'
    );

    $check(
        is_string($password) && $password !== '',
        'Test administrator password is configured'
    );

    $authentication = new AuthService();
    $loginResult = $authentication->attempt(
        is_string($username) ? $username : '',
        is_string($password) ? $password : ''
    );

    $check(
        $loginResult['successful'] === true,
        'Platform administrator can authenticate'
    );

    $userId = $_SESSION['auth']['user_id'] ?? null;
    $company = $_SESSION['auth']['company'] ?? null;
    $companies = $_SESSION['auth']['companies'] ?? null;
    $modules = $_SESSION['auth']['modules'] ?? null;

    $check(
        is_int($userId) && $userId > 0,
        'Authenticated session contains a valid user ID'
    );

    $check(
        !empty($_SESSION['auth']['is_platform_admin']),
        'Authenticated account has platform-admin context'
    );

    $check(
        is_array($company)
        && ($company['code'] ?? null) === 'default',
        'Platform administrator starts in the vendor workspace'
    );

    $check(
        is_array($companies) && count($companies) === 1,
        'Platform administrator is isolated to the vendor workspace'
    );

    $check(
        is_array($modules) && $modules === [],
        'Vendor workspace does not expose tenant module navigation'
    );

    $check(
        $authentication->can('dashboard.view'),
        'Effective RBAC permissions include dashboard access'
    );

    $check(
        !$authentication->mustChangePassword(),
        'Standard authenticated account is not forced into password change'
    );

    $dashboardStatistics =
        (new DashboardService())->statistics();

    $check(
        array_keys($dashboardStatistics) === [
            'users',
            'successfulLogins',
            'failedLogins',
            'securityAlerts',
        ],
        'Dashboard service obtains metrics through its repository contract'
    );

    $tenantStatement = db()->prepare(
        'SELECT company_id
         FROM companies
         WHERE code = :code
         LIMIT 1'
    );
    $tenantStatement->execute([
        'code' => 'test_tenant_b',
    ]);
    $tenantId = (int) $tenantStatement->fetchColumn();

    $check(
        $tenantId > 0,
        'Isolation fixture company exists'
    );

    $check(
        !$authentication->switchCompany($tenantId),
        'Platform administrator cannot switch into an unassigned tenant'
    );

    if (is_int($userId) && $userId > 0) {
        $membership = (new CompanyMembership())
            ->activeMembership(
                $userId,
                $tenantId,
                true
            );

        $check(
            $membership === null,
            'Direct membership lookup enforces vendor isolation'
        );

        $attemptStatement = db()->prepare(
            'SELECT COUNT(*)
             FROM login_attempts
             WHERE user_id = :user_id
               AND successful = TRUE'
        );
        $attemptStatement->execute([
            'user_id' => $userId,
        ]);

        $check(
            (int) $attemptStatement->fetchColumn() >= 1,
            'Successful authentication is recorded'
        );

        $auditStatement = db()->prepare(
            'SELECT COUNT(*)
             FROM audit_logs
             WHERE user_id = :user_id
               AND action = :action'
        );
        $auditStatement->execute([
            'user_id' => $userId,
            'action' => 'LOGIN',
        ]);

        $check(
            (int) $auditStatement->fetchColumn() >= 1,
            'Successful authentication is audited'
        );
    }

    $restrictedAuthentication = new AuthService();
    $restrictedLogin =
        $restrictedAuthentication->attempt(
            'test_no_dashboard',
            is_string($password) ? $password : ''
        );

    $check(
        $restrictedLogin['successful'] === true,
        'Restricted access-gate fixture can authenticate'
    );

    $check(
        !$restrictedAuthentication->can(
            'dashboard.view'
        ),
        'Restricted access-gate fixture lacks dashboard.view'
    );

    $passwordChangeAuthentication =
        new AuthService();
    $passwordChangeLogin =
        $passwordChangeAuthentication->attempt(
            'test_password_change',
            is_string($password) ? $password : ''
        );

    $check(
        $passwordChangeLogin['successful'] === true,
        'Password-change access-gate fixture can authenticate'
    );

    $check(
        $passwordChangeAuthentication
            ->mustChangePassword(),
        'Password-change fixture is marked for the central gate'
    );

    $defaultCompanyId = is_array($company)
        ? (int) ($company['company_id'] ?? 0)
        : 0;
    $platformProfile = is_int($userId)
        ? (new User())->findByIdInCompany(
            $userId,
            $defaultCompanyId
        )
        : null;
    $nonPlatformActorId = (int) (
        $_SESSION['auth']['user_id'] ?? 0
    );

    $check(
        is_array($platformProfile)
        && !empty(
            $platformProfile[
                'is_platform_admin'
            ]
        ),
        'Company-scoped user lookup identifies platform administrators'
    );

    if (
        is_array($platformProfile)
        && is_int($userId)
    ) {
        $protection =
            new PlatformAdministratorProtectionService();
        $managementError =
            $protection->managementError(
                $platformProfile,
                $nonPlatformActorId
            );

        $check(
            is_string($managementError)
            && str_contains(
                $managementError,
                'only be managed'
            ),
            'Non-platform actors cannot manage a platform administrator'
        );

        $lastAdministratorError = null;
        \db()->beginTransaction();

        try {
            $lastAdministratorError =
                $protection->deactivationError(
                    $platformProfile,
                    false
                );
        } finally {
            if (\db()->inTransaction()) {
                \db()->rollBack();
            }
        }

        $check(
            is_string($lastAdministratorError)
            && str_contains(
                $lastAdministratorError,
                'last active platform administrator'
            ),
            'Database-locked policy protects the final platform administrator'
        );

        $statusResult =
            (new UserAccountStatusService())
                ->change(
                    $userId,
                    false,
                    $nonPlatformActorId
                );
        $check(
            $statusResult['successful'] === false
            && isset(
                $statusResult['errors']['form']
            ),
            'Status service rejects unauthorized platform-account changes'
        );

        $updateResult =
            (new UserUpdateService())->update(
                $userId,
                [],
                $nonPlatformActorId
            );
        $check(
            $updateResult['successful'] === false
            && isset(
                $updateResult['errors']['form']
            ),
            'Profile service rejects unauthorized platform-account changes'
        );

        $resetResult =
            (new UserPasswordResetService())
                ->reset(
                    $userId,
                    $nonPlatformActorId
                );
        $check(
            $resetResult['successful'] === false
            && isset(
                $resetResult['errors']['form']
            ),
            'Password-reset service protects platform accounts'
        );

        $unlockResult =
            (new UserAccountUnlockService())
                ->unlock(
                    $userId,
                    $nonPlatformActorId
                );
        $check(
            $unlockResult['successful'] === false
            && isset(
                $unlockResult['errors']['form']
            ),
            'Unlock service protects platform accounts'
        );
    }

    $lifecycleAccounts = [
        'test_company_pending_user' =>
            'Pending companies cannot authenticate',
        'test_company_inactive_user' =>
            'Inactive companies cannot authenticate',
        'test_company_suspended_user' =>
            'Suspended subscriptions cannot authenticate',
        'test_company_expired_user' =>
            'Expired subscriptions cannot authenticate',
    ];

    foreach (
        $lifecycleAccounts
        as $lifecycleUsername => $description
    ) {
        unset($_SESSION['auth']);
        $lifecycleAuthentication =
            new AuthService();
        $lifecycleLogin =
            $lifecycleAuthentication->attempt(
                $lifecycleUsername,
                is_string($password)
                    ? $password
                    : ''
            );

        $check(
            $lifecycleLogin['successful'] === false
            && !isset($_SESSION['auth']),
            $description
        );
    }

    $tenantAAuthentication = new AuthService();
    $tenantALogin = $tenantAAuthentication->attempt(
        'test_tenant_a_admin',
        is_string($password) ? $password : ''
    );
    $tenantACompany = $_SESSION['auth']['company']
        ?? null;
    $tenantACompanyId = is_array($tenantACompany)
        ? (int) (
            $tenantACompany['company_id'] ?? 0
        )
        : 0;
    $tenantAActorId = (int) (
        $_SESSION['auth']['user_id'] ?? 0
    );

    $check(
        $tenantALogin['successful'] === true
        && is_array($tenantACompany)
        && ($tenantACompany['code'] ?? null)
            === 'test_tenant_a',
        'Tenant A administrator authenticates into Tenant A'
    );

    $check(
        $tenantAAuthentication->can(
            'administration.users.manage'
        )
        && $tenantAAuthentication->can(
            'administration.roles.manage'
        ),
        'Tenant A administrator has tenant user-management permissions'
    );

    $check(
        $tenantAAuthentication->can(
            'organization.branches.view'
        )
        && $tenantAAuthentication->can(
            'organization.branches.manage'
        ),
        'Tenant A administrator has branch-management permissions'
    );

    $check(
        $tenantAAuthentication->can(
            'organization.job_titles.view'
        )
        && $tenantAAuthentication->can(
            'organization.job_titles.manage'
        ),
        'Tenant A administrator has job-title management permissions'
    );

    $check(
        $tenantAAuthentication->can(
            'organization.departments.view'
        )
        && $tenantAAuthentication->can(
            'organization.departments.manage'
        ),
        'Tenant A administrator has department-management permissions'
    );

    $check(
        $tenantAAuthentication->can(
            'organization.positions.view'
        )
        && $tenantAAuthentication->can(
            'organization.positions.manage'
        ),
        'Tenant A administrator has position-management permissions'
    );

    $tenantAModules = is_array(
        $_SESSION['auth']['modules'] ?? null
    )
        ? $_SESSION['auth']['modules']
        : [];
    $tenantAModuleCodes = array_values(
        array_map(
            static fn (array $module): string =>
                (string) ($module['code'] ?? ''),
            $tenantAModules
        )
    );
    $companyModules = new CompanyModuleService();

    $check(
        $tenantAModuleCodes === [
            'hr',
            'attendance',
        ],
        'Tenant navigation exposes only licensed and enabled modules'
    );

    $check(
        $companyModules->isEnabled('hr'),
        'Licensed HR module passes the central entitlement check'
    );

    $check(
        $companyModules->isEnabled('attendance'),
        'Licensed Attendance module passes the central entitlement check'
    );

    $check(
        !$companyModules->isEnabled('finance'),
        'Unlicensed Finance module fails the central entitlement check'
    );

    $foreignEmployeeId = 920002;
    $foreignDepartmentId = 9202;
    $employeeDirectory =
        new EmployeeDirectoryService();
    $foreignEmployeeSearch =
        $employeeDirectory->directory(
            'TB-EMP-CONFIDENTIAL',
            '',
            0,
            1
        );

    $check(
        ($foreignEmployeeSearch['employees'] ?? null)
            === []
        && (
            $foreignEmployeeSearch[
                'pagination'
            ]['total'] ?? null
        ) === 0,
        'Tenant A employee directory does not reveal Tenant B records'
    );

    $check(
        $employeeDirectory->profile(
            $foreignEmployeeId
        ) === null,
        'Tenant A cannot open a Tenant B employee profile'
    );

    $check(
        (new EmployeeActivityService())->listing(
            $foreignEmployeeId,
            1
        ) === null,
        'Tenant A cannot read Tenant B employee activity'
    );

    $foreignEmployeeUpdate =
        (new EmployeeUpdateService())->update(
            $foreignEmployeeId,
            [],
            $tenantAActorId
        );
    $check(
        $foreignEmployeeUpdate['successful']
            === false
        && !empty(
            $foreignEmployeeUpdate['notFound']
        ),
        'Tenant A cannot update a Tenant B employee'
    );

    $departmentManagement =
        new DepartmentCatalogueService();
    $departmentCatalogue =
        $departmentManagement->catalogue();
    $departmentNames = array_map(
        static fn (array $department): string =>
            (string) ($department['name'] ?? ''),
        $departmentCatalogue['departments']
    );

    $check(
        in_array(
            'Tenant A Security',
            $departmentNames,
            true
        )
        && in_array(
            'Tenant A Security Operations',
            $departmentNames,
            true
        )
        && !in_array(
            'Tenant B Confidential',
            $departmentNames,
            true
        ),
        'Tenant A department catalogue excludes Tenant B records'
    );

    $hierarchyFixture = null;

    foreach (
        $departmentCatalogue['departments']
        as $department
    ) {
        if (
            (int) ($department['department_id'] ?? 0)
            === 9203
        ) {
            $hierarchyFixture = $department;
            break;
        }
    }

    $check(
        is_array($hierarchyFixture)
        && (int) (
            $hierarchyFixture[
                'parent_department_id'
            ] ?? 0
        ) === 9201
        && (
            $hierarchyFixture[
                'parent_department_name'
            ] ?? null
        ) === 'Tenant A Security',
        'Department catalogue resolves tenant-scoped hierarchy'
    );

    $check(
        $departmentManagement->form(
            $foreignDepartmentId
        ) === null,
        'Tenant A cannot open a Tenant B department'
    );

    $foreignDepartmentUpdate =
        $departmentManagement->update(
            $foreignDepartmentId,
            [],
            $tenantAActorId
        );
    $check(
        $foreignDepartmentUpdate['successful']
            === false
        && !empty(
            $foreignDepartmentUpdate['notFound']
        ),
        'Tenant A cannot update a Tenant B department'
    );

    $crossTenantParent =
        $departmentManagement->create(
            [
                'code' => 'BAD-PARENT',
                'name' =>
                    'Cross Tenant Parent Attempt',
                'parent_department_id' => 9202,
                'description' =>
                    'Must be rejected',
                'active' => true,
            ],
            $tenantAActorId
        );
    $check(
        $crossTenantParent['successful'] === false
        && isset(
            $crossTenantParent['errors'][
                'parent_department_id'
            ]
        ),
        'Department hierarchy rejects a foreign-company parent'
    );

    $cycleAttempt =
        $departmentManagement->update(
            9201,
            [
                'code' => 'TA-SEC',
                'name' => 'Tenant A Security',
                'parent_department_id' => 9203,
                'description' =>
                    'Cross-company isolation fixture',
                'active' => true,
            ],
            $tenantAActorId
        );
    $check(
        $cycleAttempt['successful'] === false
        && isset(
            $cycleAttempt['errors'][
                'parent_department_id'
            ]
        ),
        'Department hierarchy rejects cycles'
    );

    $departmentSuffix = strtoupper(
        bin2hex(random_bytes(4))
    );
    $departmentInput = [
        'code' => 'DP-' . $departmentSuffix,
        'name' => 'Integration Department '
            . $departmentSuffix,
        'parent_department_id' => 9201,
        'description' =>
            'Automated department integration fixture',
        'active' => true,
    ];
    $createdDepartment =
        $departmentManagement->create(
            $departmentInput,
            $tenantAActorId
        );

    $check(
        $createdDepartment['successful'] === true
        && (int) (
            $createdDepartment['departmentId'] ?? 0
        ) > 0,
        'Tenant department creation succeeds with a valid parent'
    );

    $duplicateDepartment =
        $departmentManagement->create(
            $departmentInput,
            $tenantAActorId
        );
    $check(
        $duplicateDepartment['successful'] === false
        && isset(
            $duplicateDepartment['errors']['code']
        ),
        'Tenant department creation rejects duplicate codes'
    );

    $createdDepartmentId = (int) (
        $createdDepartment['departmentId'] ?? 0
    );
    $departmentAuditStatement = db()->prepare(
        'SELECT COUNT(*)
         FROM audit_logs
         WHERE company_id = :company_id
           AND action = :action
           AND module = :module
           AND table_name = :table_name
           AND record_id = :record_id'
    );
    $departmentAuditStatement->execute([
        'company_id' => $tenantACompanyId,
        'action' => 'CREATE',
        'module' => 'organization',
        'table_name' => 'hr_departments',
        'record_id' =>
            (string) $createdDepartmentId,
    ]);
    $check(
        (int) $departmentAuditStatement
            ->fetchColumn() === 1,
        'Department creation records a company-scoped audit event'
    );

    $branchManagement =
        new BranchManagementService();
    $branchListing = $branchManagement->listing();
    $branchNames = array_map(
        static fn (array $branch): string =>
            (string) ($branch['name'] ?? ''),
        $branchListing['branches']
    );

    $check(
        in_array(
            'Tenant A Headquarters',
            $branchNames,
            true
        )
        && !in_array(
            'Tenant B Confidential Branch',
            $branchNames,
            true
        ),
        'Tenant A branch directory excludes Tenant B branches'
    );

    $check(
        $branchManagement->form(930002) === null,
        'Tenant A cannot open a Tenant B branch'
    );

    $foreignBranchUpdate =
        $branchManagement->update(
            930002,
            [],
            $tenantAActorId
        );
    $check(
        $foreignBranchUpdate['successful']
            === false
        && !empty(
            $foreignBranchUpdate['notFound']
        ),
        'Tenant A cannot update a Tenant B branch'
    );

    $branchSuffix = strtoupper(
        bin2hex(random_bytes(4))
    );
    $branchInput = [
        'code' => 'BR-' . $branchSuffix,
        'name' => 'Integration Branch '
            . $branchSuffix,
        'contact_email' =>
            'branch-' . strtolower($branchSuffix)
            . '@example.test',
        'contact_phone' => '+254700009999',
        'address_line' => 'Integration Avenue',
        'city' => 'Nairobi',
        'country_code' => 'KE',
        'timezone' => 'Africa/Nairobi',
        'is_head_office' => false,
        'active' => true,
    ];
    $createdBranch = $branchManagement->create(
        $branchInput,
        $tenantAActorId
    );

    $check(
        $createdBranch['successful'] === true
        && (int) (
            $createdBranch['branchId'] ?? 0
        ) > 0,
        'Tenant branch creation succeeds with valid data'
    );

    $duplicateBranch = $branchManagement->create(
        $branchInput,
        $tenantAActorId
    );
    $check(
        $duplicateBranch['successful'] === false
        && isset(
            $duplicateBranch['errors']['code']
        ),
        'Tenant branch creation rejects duplicate codes'
    );

    $createdBranchId = (int) (
        $createdBranch['branchId'] ?? 0
    );
    $branchAuditStatement = db()->prepare(
        'SELECT COUNT(*)
         FROM audit_logs
         WHERE company_id = :company_id
           AND action = :action
           AND module = :module
           AND table_name = :table_name
           AND record_id = :record_id'
    );
    $branchAuditStatement->execute([
        'company_id' => $tenantACompanyId,
        'action' => 'CREATE',
        'module' => 'organization',
        'table_name' =>
            'organization_branches',
        'record_id' => (string) $createdBranchId,
    ]);
    $check(
        (int) $branchAuditStatement
            ->fetchColumn() === 1,
        'Branch creation records a company-scoped audit event'
    );

    $duplicateHeadOffice =
        $branchManagement->create(
            array_merge($branchInput, [
                'code' => 'HQ-' . $branchSuffix,
                'name' => 'Second Headquarters '
                    . $branchSuffix,
                'is_head_office' => true,
            ]),
            $tenantAActorId
        );
    $check(
        $duplicateHeadOffice['successful']
            === false
        && isset(
            $duplicateHeadOffice['errors'][
                'is_head_office'
            ]
        ),
        'Branch rules enforce one head office per company'
    );

    $jobTitleManagement =
        new JobTitleManagementService();
    $jobTitleListing =
        $jobTitleManagement->listing();
    $jobTitleNames = array_map(
        static fn (array $jobTitle): string =>
            (string) ($jobTitle['name'] ?? ''),
        $jobTitleListing['jobTitles']
    );

    $check(
        in_array(
            'Tenant A Security Analyst',
            $jobTitleNames,
            true
        )
        && !in_array(
            'Tenant B Confidential Manager',
            $jobTitleNames,
            true
        ),
        'Tenant A job-title catalogue excludes Tenant B records'
    );

    $check(
        $jobTitleManagement->form(940002) === null,
        'Tenant A cannot open a Tenant B job title'
    );

    $foreignJobTitleUpdate =
        $jobTitleManagement->update(
            940002,
            [],
            $tenantAActorId
        );
    $check(
        $foreignJobTitleUpdate['successful']
            === false
        && !empty(
            $foreignJobTitleUpdate['notFound']
        ),
        'Tenant A cannot update a Tenant B job title'
    );

    $jobTitleSuffix = strtoupper(
        bin2hex(random_bytes(4))
    );
    $jobTitleInput = [
        'code' => 'JT-' . $jobTitleSuffix,
        'name' => 'Integration Specialist '
            . $jobTitleSuffix,
        'job_family' => 'Integration Services',
        'grade_level' => 'P2',
        'description' =>
            'Automated job-title integration fixture',
        'active' => true,
    ];
    $createdJobTitle =
        $jobTitleManagement->create(
            $jobTitleInput,
            $tenantAActorId
        );

    $check(
        $createdJobTitle['successful'] === true
        && (int) (
            $createdJobTitle['jobTitleId'] ?? 0
        ) > 0,
        'Tenant job-title creation succeeds with valid data'
    );

    $duplicateJobTitle =
        $jobTitleManagement->create(
            $jobTitleInput,
            $tenantAActorId
        );
    $check(
        $duplicateJobTitle['successful'] === false
        && isset(
            $duplicateJobTitle['errors']['code']
        ),
        'Tenant job-title creation rejects duplicate codes'
    );

    $createdJobTitleId = (int) (
        $createdJobTitle['jobTitleId'] ?? 0
    );
    $jobTitleAuditStatement = db()->prepare(
        'SELECT COUNT(*)
         FROM audit_logs
         WHERE company_id = :company_id
           AND action = :action
           AND module = :module
           AND table_name = :table_name
           AND record_id = :record_id'
    );
    $jobTitleAuditStatement->execute([
        'company_id' => $tenantACompanyId,
        'action' => 'CREATE',
        'module' => 'organization',
        'table_name' =>
            'organization_job_titles',
        'record_id' =>
            (string) $createdJobTitleId,
    ]);
    $check(
        (int) $jobTitleAuditStatement
            ->fetchColumn() === 1,
        'Job-title creation records a company-scoped audit event'
    );

    $positionManagement =
        new PositionManagementService();
    $positionListing =
        $positionManagement->listing();
    $positionNames = array_map(
        static fn (array $position): string =>
            (string) ($position['name'] ?? ''),
        $positionListing['positions']
    );

    $check(
        in_array(
            'Tenant A Security Analyst Position',
            $positionNames,
            true
        )
        && !in_array(
            'Tenant B Confidential Position',
            $positionNames,
            true
        ),
        'Tenant A position catalogue excludes Tenant B records'
    );

    $check(
        (
            $positionListing['summary'][
                'approvedHeadcount'
            ] ?? 0
        ) >= 3,
        'Position catalogue summarizes approved headcount'
    );

    $check(
        $positionManagement->form(950002) === null,
        'Tenant A cannot open a Tenant B position'
    );

    $foreignPositionUpdate =
        $positionManagement->update(
            950002,
            [],
            $tenantAActorId
        );
    $check(
        $foreignPositionUpdate['successful']
            === false
        && !empty(
            $foreignPositionUpdate['notFound']
        ),
        'Tenant A cannot update a Tenant B position'
    );

    $crossTenantPosition =
        $positionManagement->create(
            [
                'code' => 'TA-CROSS-POS',
                'name' => 'Cross Tenant Position',
                'branch_id' => 930002,
                'department_id' => 9201,
                'job_title_id' => 940001,
                'approved_headcount' => 1,
                'status' => 'planned',
            ],
            $tenantAActorId
        );
    $check(
        $crossTenantPosition['successful']
            === false
        && isset(
            $crossTenantPosition['errors'][
                'branch_id'
            ]
        ),
        'Position creation rejects a foreign-company branch'
    );

    $positionSuffix = strtoupper(
        bin2hex(random_bytes(4))
    );
    $positionInput = [
        'code' => 'PS-' . $positionSuffix,
        'name' => 'Integration Position '
            . $positionSuffix,
        'branch_id' => 930001,
        'department_id' => 9201,
        'job_title_id' => 940001,
        'approved_headcount' => 2,
        'status' => 'open',
        'description' =>
            'Automated position integration fixture',
    ];
    $createdPosition =
        $positionManagement->create(
            $positionInput,
            $tenantAActorId
        );

    $check(
        $createdPosition['successful'] === true
        && (int) (
            $createdPosition['positionId'] ?? 0
        ) > 0,
        'Tenant position creation succeeds with valid organization references'
    );

    $duplicatePosition =
        $positionManagement->create(
            $positionInput,
            $tenantAActorId
        );
    $check(
        $duplicatePosition['successful'] === false
        && isset(
            $duplicatePosition['errors']['code']
        ),
        'Tenant position creation rejects duplicate codes'
    );

    $createdPositionId = (int) (
        $createdPosition['positionId'] ?? 0
    );
    $positionAuditStatement = db()->prepare(
        'SELECT COUNT(*)
         FROM audit_logs
         WHERE company_id = :company_id
           AND action = :action
           AND module = :module
           AND table_name = :table_name
           AND record_id = :record_id'
    );
    $positionAuditStatement->execute([
        'company_id' => $tenantACompanyId,
        'action' => 'CREATE',
        'module' => 'organization',
        'table_name' =>
            'organization_positions',
        'record_id' =>
            (string) $createdPositionId,
    ]);
    $check(
        (int) $positionAuditStatement
            ->fetchColumn() === 1,
        'Position creation records a company-scoped audit event'
    );

    $positionAssignments =
        new EmployeePositionAssignmentService();
    $assignmentOverview =
        $positionAssignments->overview(920001);
    $foreignAssignmentForm =
        $positionAssignments->form(920002);

    $check(
        is_array($assignmentOverview['current'])
        && (int) (
            $assignmentOverview['current'][
                'position_id'
            ] ?? 0
        ) === 950001
        && count(
            $assignmentOverview['history']
        ) === 1,
        'Employee profile resolves the tenant-scoped current position and history'
    );

    $check(
        $foreignAssignmentForm === null,
        'Tenant A cannot open a Tenant B employee position assignment'
    );

    $assignmentForm =
        $positionAssignments->form(920001);
    $assignmentPositionIds = array_map(
        static fn (array $position): int =>
            (int) ($position['position_id'] ?? 0),
        is_array($assignmentForm)
            ? $assignmentForm['positions']
            : []
    );
    $check(
        in_array(
            $createdPositionId,
            $assignmentPositionIds,
            true
        )
        && !in_array(
            950002,
            $assignmentPositionIds,
            true
        ),
        'Position assignment options exclude foreign-company positions'
    );

    $crossTenantAssignment =
        $positionAssignments->assign(
            920001,
            [
                'position_id' => '950002',
                'effective_from' => '2026-07-28',
                'notes' => '',
            ],
            $tenantAActorId
        );
    $check(
        $crossTenantAssignment['successful']
            === false
        && isset(
            $crossTenantAssignment['errors'][
                'position_id'
            ]
        ),
        'Position assignment rejects a foreign-company position'
    );

    $transferResult = $positionAssignments->assign(
        920001,
        [
            'position_id' =>
                (string) $createdPositionId,
            'effective_from' => '2026-07-28',
            'notes' =>
                'Approved integration transfer',
        ],
        $tenantAActorId
    );
    $check(
        $transferResult['successful'] === true,
        'Employee transfer to available approved headcount succeeds'
    );

    $transferredOverview =
        $positionAssignments->overview(920001);
    $check(
        (int) (
            $transferredOverview['current'][
                'position_id'
            ] ?? 0
        ) === $createdPositionId
        && count(
            $transferredOverview['history']
        ) === 2
        && (
            $transferredOverview['history'][1][
                'assignment_status'
            ] ?? null
        ) === 'ended'
        && substr(
            (string) (
                $transferredOverview['history'][1][
                    'effective_to'
                ] ?? ''
            ),
            0,
            10
        ) === '2026-07-28',
        'Transfer closes the former assignment and preserves effective-dated history'
    );

    $samePositionTransfer =
        $positionAssignments->assign(
            920001,
            [
                'position_id' =>
                    (string) $createdPositionId,
                'effective_from' => '2026-07-28',
                'notes' => '',
            ],
            $tenantAActorId
        );
    $check(
        $samePositionTransfer['successful']
            === false
        && isset(
            $samePositionTransfer['errors'][
                'position_id'
            ]
        ),
        'Assignment workflow rejects a duplicate current position'
    );

    $assignmentAuditStatement = db()->prepare(
        'SELECT COUNT(*)
         FROM audit_logs
         WHERE company_id = :company_id
           AND action = :action
           AND module = :module
           AND table_name = :table_name'
    );
    $assignmentAuditStatement->execute([
        'company_id' => $tenantACompanyId,
        'action' => 'TRANSFER_POSITION',
        'module' => 'hr',
        'table_name' =>
            'hr_employee_position_assignments',
    ]);
    $check(
        (int) $assignmentAuditStatement
            ->fetchColumn() === 1,
        'Employee transfer records a company-scoped audit event'
    );

    $attendanceManagement =
        new AttendanceManagementService();
    $attendanceDashboard =
        $attendanceManagement->dashboard(
            '2026-07-28'
        );
    $attendanceEmployeeIds = array_map(
        static fn (array $record): int =>
            (int) ($record['employee_id'] ?? 0),
        $attendanceDashboard['records'] ?? []
    );

    $check(
        in_array(920001, $attendanceEmployeeIds, true)
        && !in_array(
            920002,
            $attendanceEmployeeIds,
            true
        ),
        'Attendance roster contains only active-company employees'
    );

    $invalidAttendance =
        $attendanceManagement->record(
            [
                'employee_id' => '920001',
                'attendance_date' => '2026-07-28',
                'attendance_status' => 'present',
                'check_in' => '25:90',
                'check_out' => '',
                'notes' => '',
            ],
            $tenantAActorId
        );
    $check(
        $invalidAttendance['successful'] === false
        && isset(
            $invalidAttendance['errors']['check_in']
        ),
        'Attendance entry rejects invalid time values'
    );

    $foreignAttendance =
        $attendanceManagement->record(
            [
                'employee_id' => '920002',
                'attendance_date' => '2026-07-28',
                'attendance_status' => 'present',
                'check_in' => '08:00',
                'check_out' => '17:00',
                'notes' => '',
            ],
            $tenantAActorId
        );
    $check(
        $foreignAttendance['successful'] === false
        && isset(
            $foreignAttendance['errors'][
                'employee_id'
            ]
        ),
        'Attendance entry rejects a foreign-company employee'
    );

    $attendanceResult =
        $attendanceManagement->record(
            [
                'employee_id' => '920001',
                'attendance_date' => '2026-07-28',
                'attendance_status' => 'present',
                'check_in' => '08:10',
                'check_out' => '17:00',
                'notes' =>
                    'Integration attendance record',
            ],
            $tenantAActorId
        );
    $recordedAttendance =
        $attendanceManagement->dashboard(
            '2026-07-28'
        );
    $check(
        $attendanceResult['successful'] === true
        && (
            $recordedAttendance['summary'][
                'present'
            ] ?? 0
        ) === 1
        && (
            $recordedAttendance['summary'][
                'recorded'
            ] ?? 0
        ) === 1,
        'Tenant attendance entry is saved and summarized'
    );

    $attendanceAuditStatement = db()->prepare(
        'SELECT COUNT(*)
         FROM audit_logs
         WHERE company_id = :company_id
           AND action = :action
           AND module = :module
           AND table_name = :table_name'
    );
    $attendanceAuditStatement->execute([
        'company_id' => $tenantACompanyId,
        'action' => 'RECORD_ATTENDANCE',
        'module' => 'attendance',
        'table_name' => 'attendance_records',
    ]);
    $check(
        (int) $attendanceAuditStatement
            ->fetchColumn() === 1,
        'Attendance entry records a company-scoped audit event'
    );

    $leaveManagement = new LeaveManagementService();
    $leaveDashboard = $leaveManagement->dashboard();
    $leaveEmployeeIds = array_map(
        static fn (array $employee): int =>
            (int) ($employee['employee_id'] ?? 0),
        $leaveDashboard['employees'] ?? []
    );
    $leaveTypeIds = array_map(
        static fn (array $type): int =>
            (int) ($type['leave_type_id'] ?? 0),
        $leaveDashboard['leaveTypes'] ?? []
    );

    $check(
        in_array(920001, $leaveEmployeeIds, true)
        && !in_array(920002, $leaveEmployeeIds, true)
        && in_array(970001, $leaveTypeIds, true)
        && !in_array(970002, $leaveTypeIds, true),
        'Leave form options are isolated to the active company'
    );

    $foreignLeave = $leaveManagement->create(
        [
            'employee_id' => '920002',
            'leave_type_id' => '970001',
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-12',
            'reason' => '',
        ],
        $tenantAActorId
    );
    $check(
        $foreignLeave['successful'] === false
        && isset(
            $foreignLeave['errors']['employee_id']
        ),
        'Leave request rejects a foreign-company employee'
    );

    $leaveResult = $leaveManagement->create(
        [
            'employee_id' => '920001',
            'leave_type_id' => '970001',
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-12',
            'reason' =>
                'Integration annual leave request',
        ],
        $tenantAActorId
    );
    $leaveRequestId = (int) (
        $leaveResult['leaveRequestId'] ?? 0
    );
    $leaveDecision = $leaveManagement->decide(
        $leaveRequestId,
        'approved',
        'Approved by integration workflow',
        $tenantAActorId
    );
    $approvedLeaveDashboard =
        $leaveManagement->dashboard('approved');
    $check(
        $leaveResult['successful'] === true
        && $leaveRequestId > 0
        && $leaveDecision['successful'] === true
        && count(
            $approvedLeaveDashboard['requests']
            ?? []
        ) === 1
        && (
            $approvedLeaveDashboard['summary'][
                'approved'
            ] ?? 0
        ) === 1,
        'Leave request and approval workflow completes successfully'
    );

    $leaveAuditStatement = db()->prepare(
        'SELECT COUNT(*)
         FROM audit_logs
         WHERE company_id = :company_id
           AND module = :module
           AND table_name = :table_name
           AND action IN (
               \'REQUEST_LEAVE\',
               \'APPROVED_LEAVE\'
           )'
    );
    $leaveAuditStatement->execute([
        'company_id' => $tenantACompanyId,
        'module' => 'hr',
        'table_name' => 'hr_leave_requests',
    ]);
    $check(
        (int) $leaveAuditStatement
            ->fetchColumn() === 2,
        'Leave workflow records company-scoped audit events'
    );

    $selfWorkspace = $leaveManagement->workspace(
        910004,
        '',
        false,
        false,
        false,
        true,
        true
    );
    $selfLeave = $leaveManagement->createForActor(
        [
            'employee_id' => '920002',
            'leave_type_id' => '970001',
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-08',
            'reason' => 'Employee self-service request',
        ],
        910004,
        false,
        true
    );
    $selfLeaveRequestId = (int) (
        $selfLeave['leaveRequestId'] ?? 0
    );
    $managerWorkspace =
        $leaveManagement->workspace(
            $tenantAActorId,
            'pending',
            false,
            false,
            false,
            false,
            true
        );
    $managerRequestIds = array_map(
        static fn (array $request): int =>
            (int) (
                $request['leave_request_id'] ?? 0
            ),
        $managerWorkspace['requests'] ?? []
    );
    $managerDecision =
        $leaveManagement->decideForActor(
            $selfLeaveRequestId,
            'approved',
            'Approved by direct manager',
            $tenantAActorId,
            false,
            true
        );
    $foreignManagerDecision =
        $leaveManagement->decideForActor(
            999999,
            'approved',
            '',
            $tenantAActorId,
            false,
            true
        );

    $check(
        ($selfWorkspace['employee']['employee_id']
            ?? null) === 920001
        && !empty(
            $selfWorkspace['canRequestSelf']
        )
        && $selfLeave['successful'] === true
        && $selfLeaveRequestId > 0,
        'Linked employee can view and submit personal leave'
    );
    $check(
        in_array(
            $selfLeaveRequestId,
            $managerRequestIds,
            true
        )
        && $managerDecision['successful'] === true,
        'Reporting manager can review and decide direct-report leave'
    );
    $check(
        $foreignManagerDecision['successful']
            === false
        && !empty(
            $foreignManagerDecision['notFound']
        ),
        'Manager decision scope fails closed for unassigned requests'
    );

    $employeeRoleStatement = db()->prepare(
        'SELECT role_id
         FROM roles
         WHERE code = :code
         LIMIT 1'
    );
    $employeeRoleStatement->execute([
        'code' => 'employee_self_service',
    ]);
    $employeeRoleId = (int) (
        $employeeRoleStatement->fetchColumn()
        ?: 0
    );
    $createdEmployeeUser =
        (new UserCreationService())->create(
            [
                'username' =>
                    'test_managed_employee_user',
                'email' =>
                    'managed-employee@example.test',
                'display_name' =>
                    'Managed Employee User',
                'manager_user_id' =>
                    (string) $tenantAActorId,
                'active' => true,
                'role_ids' => [$employeeRoleId],
            ],
            $tenantAActorId
        );
    $createdEmployeeUserId = (int) (
        $createdEmployeeUser['userId'] ?? 0
    );
    $createdManagerStatement = db()->prepare(
        'SELECT manager_user_id
         FROM company_users
         WHERE company_id = :company_id
           AND user_id = :user_id'
    );
    $createdManagerStatement->execute([
        'company_id' => $tenantACompanyId,
        'user_id' => $createdEmployeeUserId,
    ]);

    $check(
        $employeeRoleId > 0
        && $createdEmployeeUser['successful']
            === true
        && (int) $createdManagerStatement
            ->fetchColumn() === $tenantAActorId,
        'Tenant user creation persists company and reporting manager'
    );

    $financeDashboard =
        new FinanceDashboardService();
    $ownFinanceSearch =
        $financeDashboard->dashboard(
            'TA-EXP-SEC',
            '',
            1
        );
    $foreignFinanceSearch =
        $financeDashboard->dashboard(
            'TB-EXP-CONFIDENTIAL',
            '',
            1
        );

    $check(
        (
            $ownFinanceSearch['pagination'][
                'total'
            ] ?? null
        ) === 1,
        'Finance search retains the active company parameter'
    );

    $check(
        ($foreignFinanceSearch['requests'] ?? null)
            === []
        && (
            $foreignFinanceSearch[
                'pagination'
            ]['total'] ?? null
        ) === 0,
        'Tenant A finance search does not reveal Tenant B records'
    );

    $tenantBTargetStatement = db()->prepare(
        'SELECT
            users.user_id,
            users.username,
            users.email,
            users.display_name,
            users.password_hash,
            users.active,
            users.must_change_password,
            users.failed_login_count,
            users.locked_until
         FROM users
         INNER JOIN company_users memberships
             ON memberships.user_id = users.user_id
         INNER JOIN companies
             ON companies.company_id =
                memberships.company_id
         WHERE users.username = :username
           AND companies.code = :company_code
         LIMIT 1'
    );
    $tenantBTargetStatement->execute([
        'username' => 'test_tenant_b_user',
        'company_code' => 'test_tenant_b',
    ]);
    $tenantBTarget = $tenantBTargetStatement->fetch(
        \PDO::FETCH_ASSOC
    );
    $tenantBUserId = is_array($tenantBTarget)
        ? (int) ($tenantBTarget['user_id'] ?? 0)
        : 0;

    $check(
        $tenantACompanyId > 0
        && $tenantAActorId > 0
        && $tenantBUserId > 0,
        'Cross-tenant isolation fixtures resolve to distinct records'
    );

    $tenantUserModel = new User();
    $check(
        $tenantUserModel->findByIdInCompany(
            $tenantBUserId,
            $tenantACompanyId
        ) === null,
        'Tenant A cannot read Tenant B user through scoped lookup'
    );

    $foreignListing =
        (new UserAdministrationService())->listing(
            'test_tenant_b_user',
            'all',
            'created_at',
            'desc',
            1
        );
    $check(
        ($foreignListing['users'] ?? null) === []
        && (
            $foreignListing['pagination']['total']
            ?? null
        ) === 0,
        'Tenant A user directory does not reveal Tenant B users'
    );

    $check(
        (new UserDetailsService())->details(
            $tenantBUserId
        ) === null,
        'Tenant A cannot open Tenant B user details'
    );

    $check(
        $tenantUserModel->roleIds(
            $tenantACompanyId,
            $tenantBUserId
        ) === [],
        'Tenant A cannot read Tenant B role assignments'
    );

    $foreignUpdate = (new UserUpdateService())->update(
        $tenantBUserId,
        [
            'username' => 'tenant-b-compromised',
            'email' => 'compromised@example.test',
            'display_name' => 'Compromised User',
            'active' => false,
            'role_ids' => [],
        ],
        $tenantAActorId
    );
    $check(
        $foreignUpdate['successful'] === false
        && !empty($foreignUpdate['notFound']),
        'Tenant A cannot update Tenant B profile or role assignments'
    );

    $foreignStatus =
        (new UserAccountStatusService())->change(
            $tenantBUserId,
            false,
            $tenantAActorId
        );
    $check(
        $foreignStatus['successful'] === false
        && !empty($foreignStatus['notFound']),
        'Tenant A cannot change Tenant B account status'
    );

    $foreignReset =
        (new UserPasswordResetService())->reset(
            $tenantBUserId,
            $tenantAActorId
        );
    $check(
        $foreignReset['successful'] === false
        && !empty($foreignReset['notFound']),
        'Tenant A cannot reset Tenant B password'
    );

    $foreignUnlock =
        (new UserAccountUnlockService())->unlock(
            $tenantBUserId,
            $tenantAActorId
        );
    $check(
        $foreignUnlock['successful'] === false
        && !empty($foreignUnlock['notFound']),
        'Tenant A cannot unlock Tenant B account'
    );

    $tenantBTargetStatement->execute([
        'username' => 'test_tenant_b_user',
        'company_code' => 'test_tenant_b',
    ]);
    $tenantBTargetAfter = $tenantBTargetStatement->fetch(
        \PDO::FETCH_ASSOC
    );

    $check(
        is_array($tenantBTarget)
        && is_array($tenantBTargetAfter)
        && $tenantBTargetAfter === $tenantBTarget,
        'Rejected cross-tenant mutations leave Tenant B unchanged'
    );

    $companyDetailsService =
        new CompanyProvisioningService();
    $tenantBCompanyDetails =
        $companyDetailsService->details($tenantId);
    $tenantBCompany = is_array(
        $tenantBCompanyDetails
    )
        ? $tenantBCompanyDetails['company']
            ?? []
        : [];
    $tenantBModules = is_array(
        $tenantBCompanyDetails
    )
        ? $tenantBCompanyDetails['modules']
            ?? []
        : [];
    $tenantBModuleCodes = array_values(
        array_map(
            static fn (array $module): string =>
                (string) $module['code'],
            array_filter(
                is_array($tenantBModules)
                    ? $tenantBModules
                    : [],
                static fn (array $module): bool =>
                    !empty($module['enabled'])
                    && in_array(
                        (string) (
                            $module['license_status']
                            ?? ''
                        ),
                        ['active', 'trial'],
                        true
                    )
            )
        )
    );
    $companyUpdateInput = [
        'name' => (string) (
            $tenantBCompany['name']
            ?? 'Test Tenant B'
        ),
        'legal_name' =>
            $tenantBCompany['legal_name'] ?? null,
        'contact_email' =>
            $tenantBCompany['contact_email']
            ?? 'tenant-b@example.test',
        'contact_phone' => '+254700000222',
        'country_code' => (string) (
            $tenantBCompany['country_code']
            ?? 'KE'
        ),
        'default_currency' => (string) (
            $tenantBCompany['default_currency']
            ?? 'KES'
        ),
        'timezone' => (string) (
            $tenantBCompany['timezone']
            ?? 'Africa/Nairobi'
        ),
        'subscription_status' => 'active',
        'subscription_expires_at' => '',
        'brand_primary_color' => (string) (
            $tenantBCompany['brand_primary_color']
            ?? '#2563EB'
        ),
        'module_codes' => $tenantBModuleCodes,
    ];
    $unauthorizedCompanyUpdate =
        (new CompanyUpdateService())->update(
            $tenantId,
            $companyUpdateInput,
            $tenantAActorId
        );
    $unauthorizedLifecycle =
        (new CompanyLifecycleService())->change(
            $tenantId,
            'suspend',
            'Unauthorized tenant administrator request.',
            $tenantAActorId
        );

    $check(
        $unauthorizedCompanyUpdate[
            'successful'
        ] === false
        && isset(
            $unauthorizedCompanyUpdate[
                'errors'
            ]['form']
        )
        && $unauthorizedLifecycle[
            'successful'
        ] === false
        && isset(
            $unauthorizedLifecycle[
                'errors'
            ]['form']
        ),
        'Tenant administrators cannot edit or suspend customer companies'
    );

    $vendorUpdate =
        (new CompanyUpdateService())->update(
            $tenantId,
            $companyUpdateInput,
            is_int($userId) ? $userId : 0
        );
    $updatedCompany = $companyDetailsService
        ->details($tenantId);

    $check(
        $vendorUpdate['successful'] === true
        && !empty($vendorUpdate['changed'])
        && (
            $updatedCompany['company'][
                'contact_phone'
            ] ?? null
        ) === '+254700000222',
        'Platform administrator can update company profile and commercial settings'
    );

    $shortReasonResult =
        (new CompanyLifecycleService())->change(
            $tenantId,
            'suspend',
            'Too short',
            is_int($userId) ? $userId : 0
        );
    $check(
        $shortReasonResult['successful']
            === false
        && isset(
            $shortReasonResult[
                'errors'
            ]['reason']
        ),
        'Company suspension requires an auditable reason'
    );

    $suspensionResult =
        (new CompanyLifecycleService())->change(
            $tenantId,
            'suspend',
            'Integration test verifies vendor suspension controls.',
            is_int($userId) ? $userId : 0
        );
    $suspendedStatus = db()->prepare(
        'SELECT subscription_status
         FROM companies
         WHERE company_id = :company_id'
    );
    $suspendedStatus->execute([
        'company_id' => $tenantId,
    ]);

    $check(
        $suspensionResult['successful'] === true
        && $suspendedStatus->fetchColumn()
            === 'suspended',
        'Vendor suspension transitions the company to suspended'
    );

    unset($_SESSION['auth']);
    $suspendedLogin =
        (new AuthService())->attempt(
            'test_tenant_b_user',
            is_string($password) ? $password : ''
        );
    $check(
        $suspendedLogin['successful'] === false
        && !isset($_SESSION['auth']),
        'Suspended company users are blocked from authentication'
    );

    $reactivationResult =
        (new CompanyLifecycleService())->change(
            $tenantId,
            'reactivate',
            '',
            is_int($userId) ? $userId : 0
        );
    $reactivatedStatus = db()->prepare(
        'SELECT subscription_status
         FROM companies
         WHERE company_id = :company_id'
    );
    $reactivatedStatus->execute([
        'company_id' => $tenantId,
    ]);
    $check(
        $reactivationResult['successful'] === true
        && $reactivatedStatus->fetchColumn()
            === 'active',
        'Vendor reactivation restores the prior commercial state'
    );

    $reactivatedLogin =
        (new AuthService())->attempt(
            'test_tenant_b_user',
            is_string($password) ? $password : ''
        );
    $check(
        $reactivatedLogin['successful'] === true
        && (
            $_SESSION['auth']['company']['code']
            ?? null
        ) === 'test_tenant_b',
        'Reactivated company users can authenticate again'
    );

    $companyAuditStatement = db()->prepare(
        'SELECT COUNT(*)
         FROM audit_logs
         WHERE company_id = :company_id
           AND action IN (
                \'UPDATE_COMPANY\',
                \'SUSPEND_COMPANY\',
                \'REACTIVATE_COMPANY\'
           )'
    );
    $companyAuditStatement->execute([
        'company_id' => $tenantId,
    ]);
    $check(
        (int) $companyAuditStatement
            ->fetchColumn() >= 3,
        'Company edits and lifecycle transitions are audited'
    );

    $delegatedAuthentication = new AuthService();
    $delegatedLogin =
        $delegatedAuthentication->attempt(
            'test_tenant_a_delegated_admin',
            is_string($password) ? $password : ''
        );
    $delegatedCompany = $_SESSION['auth']['company']
        ?? null;
    $delegatedCompanyId = is_array(
        $delegatedCompany
    )
        ? (int) (
            $delegatedCompany['company_id'] ?? 0
        )
        : 0;
    $delegatedActorId = (int) (
        $_SESSION['auth']['user_id'] ?? 0
    );

    $check(
        $delegatedLogin['successful'] === true
        && is_array($delegatedCompany)
        && ($delegatedCompany['code'] ?? null)
            === 'test_tenant_a',
        'Delegated administrator authenticates into Tenant A'
    );

    $check(
        $delegatedAuthentication->can(
            'administration.users.manage'
        )
        && $delegatedAuthentication->can(
            'administration.roles.manage'
        )
        && !$delegatedAuthentication->can(
            'security_test.elevated'
        ),
        'Delegated administrator has bounded management authority'
    );

    $privilegeFixtureStatement = db()->query(
        'SELECT
            MAX(CASE
                WHEN users.username =
                    \'test_tenant_a_target\'
                THEN users.user_id
            END) AS target_user_id,
            MAX(CASE
                WHEN roles.code =
                    \'system_administrator\'
                THEN roles.role_id
            END) AS system_role_id,
            MAX(CASE
                WHEN roles.code =
                    \'company_owner\'
                THEN roles.role_id
            END) AS owner_role_id,
            MAX(CASE
                WHEN permissions.code =
                    \'dashboard.view\'
                THEN permissions.permission_id
            END) AS dashboard_permission_id,
            MAX(CASE
                WHEN permissions.code =
                    \'administration.companies.manage\'
                THEN permissions.permission_id
            END) AS platform_permission_id
         FROM users
         CROSS JOIN roles
         CROSS JOIN permissions'
    );
    $privilegeFixture = $privilegeFixtureStatement
        ->fetch(\PDO::FETCH_ASSOC);
    $tenantATargetUserId = is_array(
        $privilegeFixture
    )
        ? (int) (
            $privilegeFixture['target_user_id']
            ?? 0
        )
        : 0;
    $systemRoleId = is_array($privilegeFixture)
        ? (int) (
            $privilegeFixture['system_role_id']
            ?? 0
        )
        : 0;
    $ownerRoleId = is_array($privilegeFixture)
        ? (int) (
            $privilegeFixture['owner_role_id']
            ?? 0
        )
        : 0;
    $dashboardPermissionId = is_array(
        $privilegeFixture
    )
        ? (int) (
            $privilegeFixture[
                'dashboard_permission_id'
            ] ?? 0
        )
        : 0;
    $platformPermissionId = is_array(
        $privilegeFixture
    )
        ? (int) (
            $privilegeFixture[
                'platform_permission_id'
            ] ?? 0
        )
        : 0;

    $check(
        $delegatedCompanyId > 0
        && $delegatedActorId > 0
        && $tenantATargetUserId === 910004
        && $systemRoleId > 0
        && $ownerRoleId > 0
        && $dashboardPermissionId > 0
        && $platformPermissionId > 0,
        'Privilege-escalation fixtures resolve correctly'
    );

    $targetRoleIdsBefore = $tenantUserModel
        ->roleIds(
            $delegatedCompanyId,
            $tenantATargetUserId
        );
    $permissionTargetBefore =
        (new \App\Models\Role())->permissionIds(
            $delegatedCompanyId,
            9102
        );

    $creationResult =
        (new UserCreationService())->create(
            [
                'username' =>
                    'test_illegal_privileged_user',
                'email' =>
                    'illegal-privileged-user@example.test',
                'display_name' =>
                    'Illegal Privileged User',
                'active' => true,
                'role_ids' => [9101],
            ],
            $delegatedActorId
        );
    $check(
        $creationResult['successful'] === false
        && isset(
            $creationResult['errors']['roles']
        ),
        'Delegated administrator cannot create a user above their authority'
    );

    $createdEscalatedUserStatement = db()->prepare(
        'SELECT COUNT(*)
         FROM users
         WHERE username = :username'
    );
    $createdEscalatedUserStatement->execute([
        'username' =>
            'test_illegal_privileged_user',
    ]);
    $check(
        (int) $createdEscalatedUserStatement
            ->fetchColumn() === 0,
        'Rejected privileged user creation writes no account'
    );

    $assignmentResult =
        (new UserUpdateService())->update(
            $tenantATargetUserId,
            [
                'username' =>
                    'test_tenant_a_target',
                'email' =>
                    'tenant-a-target@example.test',
                'display_name' =>
                    'Test Tenant A Target',
                'active' => true,
                'role_ids' => [9101],
            ],
            $delegatedActorId
        );
    $check(
        $assignmentResult['successful'] === false
        && isset(
            $assignmentResult['errors']['roles']
        ),
        'Delegated administrator cannot assign a role above their authority'
    );

    $systemRoleResult =
        (new UserUpdateService())->update(
            $tenantATargetUserId,
            [
                'username' =>
                    'test_tenant_a_target',
                'email' =>
                    'tenant-a-target@example.test',
                'display_name' =>
                    'Test Tenant A Target',
                'active' => true,
                'role_ids' => [$systemRoleId],
            ],
            $delegatedActorId
        );
    $check(
        $systemRoleResult['successful'] === false
        && isset(
            $systemRoleResult['errors']['roles']
        ),
        'Tenant administrator cannot assign the platform system role'
    );

    $permissionUpdateService =
        new RolePermissionUpdateService();
    $permissionEscalationResult =
        $permissionUpdateService->update(
            9102,
            [
                $dashboardPermissionId,
                9101,
            ],
            $delegatedActorId
        );
    $check(
        $permissionEscalationResult[
            'successful'
        ] === false
        && isset(
            $permissionEscalationResult[
                'errors'
            ]['permissions']
        ),
        'Delegated administrator cannot grant a permission they do not hold'
    );

    $platformGrantResult =
        $permissionUpdateService->update(
            9102,
            [
                $dashboardPermissionId,
                $platformPermissionId,
            ],
            $delegatedActorId
        );
    $check(
        $platformGrantResult['successful']
            === false
        && isset(
            $platformGrantResult[
                'errors'
            ]['permissions']
        ),
        'Tenant administrator cannot grant platform-only permissions'
    );

    $protectedOwnerResult =
        $permissionUpdateService->update(
            $ownerRoleId,
            [$dashboardPermissionId],
            $delegatedActorId
        );
    $check(
        $protectedOwnerResult['successful']
            === false
        && isset(
            $protectedOwnerResult[
                'errors'
            ]['form']
        ),
        'Company-owner permission baseline remains protected'
    );

    $targetRoleIdsAfter = $tenantUserModel
        ->roleIds(
            $delegatedCompanyId,
            $tenantATargetUserId
        );
    $permissionTargetAfter =
        (new \App\Models\Role())->permissionIds(
            $delegatedCompanyId,
            9102
        );
    $check(
        $targetRoleIdsAfter === $targetRoleIdsBefore
        && $permissionTargetAfter
            === $permissionTargetBefore,
        'Rejected privilege escalation leaves roles and grants unchanged'
    );
} catch (Throwable $exception) {
    $failures++;
    $results[] = [
        'passed' => false,
        'description' => sprintf(
            'Unhandled %s at %s:%d',
            $exception::class,
            $exception->getFile(),
            $exception->getLine()
        ),
    ];
}

foreach ($results as $result) {
    echo $result['passed'] ? 'PASS ' : 'FAIL ';
    echo $result['description'];
    echo PHP_EOL;
}

echo PHP_EOL;
echo sprintf(
    '%d checks, %d failures',
    count($results),
    $failures
);
echo PHP_EOL;

exit($failures === 0 ? 0 : 1);
