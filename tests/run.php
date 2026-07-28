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
use App\Services\BranchManagementService;
use App\Services\CompanyModuleService;
use App\Services\DashboardService;
use App\Services\DepartmentManagementService;
use App\Services\EmployeeActivityService;
use App\Services\EmployeeDirectoryService;
use App\Services\EmployeeUpdateService;
use App\Services\FinanceDashboardService;
use App\Services\PlatformAdministratorProtectionService;
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
        && count($oracleMigrationFiles) === 7,
        'Oracle migration catalog contains seven valid definitions'
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
        $oracleTableCount === 18
        && $oracleIdentityCount === 12,
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
        $tableCount === 18,
        'All 18 application tables were created'
    );

    $foreignKeyCount = (int) db()
        ->query(
            'SELECT COUNT(*)
             FROM information_schema.referential_constraints
             WHERE constraint_schema = DATABASE()'
        )
        ->fetchColumn();

    $check(
        $foreignKeyCount === 46,
        'All 46 foreign-key relationships were created'
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
        $tenantAModuleCodes === ['hr'],
        'Tenant navigation exposes only licensed and enabled modules'
    );

    $check(
        $companyModules->isEnabled('hr'),
        'Licensed HR module passes the central entitlement check'
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
        new DepartmentManagementService();
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
