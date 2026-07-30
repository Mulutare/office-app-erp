<?php

declare(strict_types=1);

require_once __DIR__
    . '/../app/helpers/bootstrap.php';

use App\Database\ConnectionManager;
use App\Database\MigrationRunner;
use App\Database\MySqlDialect;
use App\Database\OracleDialect;
use App\Database\OracleDriver;
use App\Database\ReferenceDataSynchronizer;
use App\Database\SqlStatementSplitter;
use App\Models\CompanyMembership;
use App\Models\User;
use App\Repositories\MySql\CompanyMembershipRepository;
use App\Repositories\MySql\DashboardStatisticsRepository;
use App\Repositories\MySql\OrganizationReadinessRepository
    as MySqlOrganizationReadinessRepository;
use App\Repositories\MySql\AttendanceReminderRepository
    as MySqlAttendanceReminderRepository;
use App\Repositories\MySql\WorkforceCalendarRepository
    as MySqlWorkforceCalendarRepository;
use App\Repositories\MySql\AttendanceNotificationRepository
    as MySqlAttendanceNotificationRepository;
use App\Repositories\Oracle\DashboardStatisticsRepository
    as OracleDashboardStatisticsRepository;
use App\Repositories\RepositoryFactory;
use App\Services\AuthService;
use App\Services\AttendanceManagementService;
use App\Services\AttendanceReminderService;
use App\Services\AttendanceSelfServiceService;
use App\Services\AttendanceNotificationService;
use App\Services\BranchManagementService;
use App\Services\CompanyModuleService;
use App\Services\CompanyLifecycleService;
use App\Services\CompanyOwnerPasswordResetService;
use App\Services\CompanyProvisioningService;
use App\Services\CompanyUpdateService;
use App\Services\DashboardService;
use App\Services\DepartmentCatalogueService;
use App\Services\DevelopmentSampleCompanyService;
use App\Services\EmployeeActivityService;
use App\Services\EmployeeDirectoryService;
use App\Services\EmployeePositionAssignmentService;
use App\Services\EmployeeUpdateService;
use App\Services\FinanceDashboardService;
use App\Services\JobTitleManagementService;
use App\Services\LeaveManagementService;
use App\Services\LeaveBalanceManagementService;
use App\Services\LeavePolicyService;
use App\Services\ManagerWorkspaceService;
use App\Services\OrganizationSetupService;
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
use App\Services\WorkforceCalendarService;

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

    $check(
        RepositoryFactory::organizationReadiness()
            instanceof MySqlOrganizationReadinessRepository,
        'Repository factory selects the MySQL organization-readiness repository'
    );

    $check(
        RepositoryFactory::attendanceReminders()
            instanceof MySqlAttendanceReminderRepository,
        'Repository factory selects the MySQL attendance-reminder repository'
    );

    $check(
        RepositoryFactory::workforceCalendars()
            instanceof MySqlWorkforceCalendarRepository
        && RepositoryFactory::attendanceNotifications()
            instanceof MySqlAttendanceNotificationRepository,
        'Repository factory selects MySQL workforce-calendar and notification repositories'
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
        && count($oracleMigrationFiles) === 21,
        'Oracle migration catalog contains twenty-one valid definitions'
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
                '140',
                '150',
                '160',
                '170',
                '180',
                '190',
                '200',
                '210',
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
        $oracleTableCount === 33
        && $oracleIdentityCount === 26,
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

    $mysqlMigrationFiles = glob(
        __DIR__
        . '/../database/migrations/mysql/*.php'
    );
    $mysqlMigrationFiles = is_array(
        $mysqlMigrationFiles
    )
        ? $mysqlMigrationFiles
        : [];
    sort($mysqlMigrationFiles, SORT_STRING);
    $mysqlMigrationVersions = [];
    $mysqlMigrationDefinitionsValid = true;

    foreach ($mysqlMigrationFiles as $migrationFile) {
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
            || !is_callable(
                $definition['preflight'] ?? null
            )
        ) {
            $mysqlMigrationDefinitionsValid = false;

            continue;
        }

        $mysqlMigrationVersions[] =
            $definition['version'];
    }

    $check(
        $mysqlMigrationDefinitionsValid
        && $mysqlMigrationVersions
            === [
                '015',
                '016',
                '017',
                '018',
                '019',
                '020',
                '021',
            ],
        'MySQL forward-migration catalog is ordered and preflight protected'
    );

    $migrationLedgerCount = (int) db()
        ->query(
            'SELECT COUNT(*)
             FROM schema_migrations
             WHERE version IN (
                \'015\',
                \'016\',
                \'017\',
                \'018\',
                \'019\',
                \'020\',
                \'021\'
             )'
        )
        ->fetchColumn();

    $check(
        $migrationLedgerCount === 7,
        'MySQL forward migrations are recorded in the migration ledger'
    );

    $checksumFixture = tempnam(
        sys_get_temp_dir(),
        'officeapp-migration-'
    );

    if (!is_string($checksumFixture)) {
        throw new RuntimeException(
            'Unable to create the migration checksum fixture.'
        );
    }

    $checksumMethod = new ReflectionMethod(
        MigrationRunner::class,
        'checksum'
    );
    $checksumMethod->setAccessible(true);
    $checksumRunner = new MigrationRunner(
        db(),
        'mysql'
    );

    file_put_contents(
        $checksumFixture,
        "<?php\r\nreturn [];\r\n"
    );
    $crlfChecksum = $checksumMethod->invoke(
        $checksumRunner,
        $checksumFixture
    );

    file_put_contents(
        $checksumFixture,
        "<?php\nreturn [];\n"
    );
    $lfChecksum = $checksumMethod->invoke(
        $checksumRunner,
        $checksumFixture
    );
    unlink($checksumFixture);

    $check(
        is_string($crlfChecksum)
        && is_string($lfChecksum)
        && hash_equals(
            $crlfChecksum,
            $lfChecksum
        ),
        'Migration checksums are stable across LF and CRLF checkouts'
    );

    $splitStatements = (
        new SqlStatementSplitter()
    )->split(
        "INSERT INTO example VALUES ('a;b');\n"
        . "-- ignored ; comment\n"
        . 'UPDATE example SET value = "c;d";'
    );

    $check(
        count($splitStatements) === 2
        && str_contains(
            $splitStatements[0],
            "'a;b'"
        )
        && str_contains(
            $splitStatements[1],
            '"c;d"'
        ),
        'SQL file splitting preserves quoted delimiters and ignores comments'
    );

    $referenceCountsBefore = db()->query(
        'SELECT
            (SELECT COUNT(*) FROM permissions)
                AS permissions_count,
            (SELECT COUNT(*) FROM role_permissions)
                AS role_permissions_count,
            (
                SELECT COUNT(*)
                FROM company_role_permissions
            ) AS company_role_permissions_count,
            (SELECT COUNT(*) FROM hr_leave_types)
                AS leave_types_count'
    )->fetch(\PDO::FETCH_ASSOC);
    $synchronization = (
        new ReferenceDataSynchronizer(
            db(),
            'mysql'
        )
    )->run(
        __DIR__ . '/../database/seeds'
    );
    $referenceCountsAfter = db()->query(
        'SELECT
            (SELECT COUNT(*) FROM permissions)
                AS permissions_count,
            (SELECT COUNT(*) FROM role_permissions)
                AS role_permissions_count,
            (
                SELECT COUNT(*)
                FROM company_role_permissions
            ) AS company_role_permissions_count,
            (SELECT COUNT(*) FROM hr_leave_types)
                AS leave_types_count'
    )->fetch(\PDO::FETCH_ASSOC);

    $check(
        count($synchronization['files']) === 17
        && $synchronization['statementCount'] > 17
        && $referenceCountsBefore
            === $referenceCountsAfter,
        'MySQL reference-data synchronization is repeatable without duplicate grants'
    );

    $hrJobTitleGrantCount = (int) db()->query(
        'SELECT COUNT(*)
         FROM role_permissions grants
         INNER JOIN roles
            ON roles.role_id = grants.role_id
         INNER JOIN permissions
            ON permissions.permission_id =
                grants.permission_id
         WHERE roles.code = \'hr_administrator\'
           AND permissions.code IN (
                \'organization.job_titles.view\',
                \'organization.job_titles.manage\'
           )'
    )->fetchColumn();

    $check(
        $hrJobTitleGrantCount === 2,
        'HR administrators can maintain job-title prerequisites for position planning'
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
               AND table_name <> \'schema_migrations\'
               AND table_type = \'BASE TABLE\''
        )
        ->fetchColumn();

    $check(
        $tableCount === 33,
        'All 33 application tables were created'
    );

    $foreignKeyCount = (int) db()
        ->query(
            'SELECT COUNT(*)
             FROM information_schema.referential_constraints
             WHERE constraint_schema = DATABASE()'
        )
        ->fetchColumn();

    $check(
        $foreignKeyCount === 98,
        'All 98 foreign-key relationships were created'
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

    $ownerRecovery =
        new CompanyOwnerPasswordResetService();
    $ownerRecoveryTarget = $ownerRecovery
        ->target(
            $tenantACompanyId,
            is_int($userId) ? $userId : 0
        );

    $check(
        is_array($ownerRecoveryTarget)
        && (int) (
            $ownerRecoveryTarget[
                'owner'
            ]['user_id'] ?? 0
        ) === $tenantAActorId
        && (string) (
            $ownerRecoveryTarget[
                'company'
            ]['code'] ?? ''
        ) === 'test_tenant_a',
        'Vendor recovery resolves the primary owner inside the selected company'
    );

    $ownerSecurityDetails = (
        new CompanyProvisioningService()
    )->details($tenantACompanyId);
    $ownerSecurityCompany = is_array(
        $ownerSecurityDetails
    )
        ? $ownerSecurityDetails['company']
            ?? []
        : [];
    $check(
        is_array($ownerSecurityCompany)
        && array_key_exists(
            'owner_account_active',
            $ownerSecurityCompany
        )
        && !empty(
            $ownerSecurityCompany[
                'owner_account_active'
            ]
        )
        && array_key_exists(
            'owner_account_locked',
            $ownerSecurityCompany
        )
        && array_key_exists(
            'owner_must_change_password',
            $ownerSecurityCompany
        ),
        'Vendor company details include primary-owner security state'
    );

    $unauthorizedOwnerReset =
        $ownerRecovery->reset(
            $tenantACompanyId,
            $tenantAActorId
        );
    $check(
        $unauthorizedOwnerReset['successful']
            === false
        && isset(
            $unauthorizedOwnerReset[
                'errors'
            ]['form']
        ),
        'Tenant administrators cannot invoke vendor owner recovery'
    );

    $ownerCredentialStatement = db()->prepare(
        'SELECT
            password_hash,
            must_change_password,
            failed_login_count,
            locked_until
         FROM users
         WHERE user_id = :user_id'
    );
    $ownerRecoveryResult = null;
    $ownerAfterRecovery = null;
    $ownerRecoveryAudit = null;

    db()->beginTransaction();

    try {
        $ownerRecoveryResult =
            $ownerRecovery->reset(
                $tenantACompanyId,
                is_int($userId) ? $userId : 0
            );
        $ownerCredentialStatement->execute([
            'user_id' => $tenantAActorId,
        ]);
        $ownerAfterRecovery =
            $ownerCredentialStatement->fetch(
                \PDO::FETCH_ASSOC
            );
        $ownerRecoveryAuditStatement =
            db()->prepare(
                'SELECT new_values
                 FROM audit_logs
                 WHERE company_id = :company_id
                   AND action = :action
                   AND table_name = :table_name
                   AND record_id = :record_id
                 ORDER BY audit_log_id DESC
                 LIMIT 1'
            );
        $ownerRecoveryAuditStatement->execute([
            'company_id' => $tenantACompanyId,
            'action' =>
                'RESET_COMPANY_OWNER_PASSWORD',
            'table_name' => 'users',
            'record_id' =>
                (string) $tenantAActorId,
        ]);
        $ownerRecoveryAudit =
            $ownerRecoveryAuditStatement
                ->fetchColumn();
    } finally {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    }

    $temporaryOwnerPassword = is_array(
        $ownerRecoveryResult
    )
        ? (string) (
            $ownerRecoveryResult[
                'temporaryPassword'
            ] ?? ''
        )
        : '';

    $check(
        is_array($ownerRecoveryResult)
        && $ownerRecoveryResult['successful']
            === true
        && is_array($ownerAfterRecovery)
        && $temporaryOwnerPassword !== ''
        && password_verify(
            $temporaryOwnerPassword,
            (string) $ownerAfterRecovery[
                'password_hash'
            ]
        )
        && !empty(
            $ownerAfterRecovery[
                'must_change_password'
            ]
        )
        && (int) $ownerAfterRecovery[
            'failed_login_count'
        ] === 0
        && $ownerAfterRecovery['locked_until']
            === null,
        'Vendor reset generates a hashed one-time owner password and clears lock state'
    );

    $check(
        is_string($ownerRecoveryAudit)
        && !str_contains(
            $ownerRecoveryAudit,
            $temporaryOwnerPassword
        ),
        'Company owner recovery is audited without storing the temporary password'
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

    $check(
        $tenantAAuthentication->can(
            'hr.leave.policy.manage'
        )
        && $tenantAAuthentication->can(
            'hr.leave.balance.manage'
        ),
        'Tenant A administrator has leave-policy and balance-management permissions'
    );

    $organizationSetup =
        new OrganizationSetupService();
    $organizationOverview =
        $organizationSetup->overview();
    $organizationMetrics = is_array(
        $organizationOverview['metrics'] ?? null
    )
        ? $organizationOverview['metrics']
        : [];
    $organizationStages = is_array(
        $organizationOverview['stages'] ?? null
    )
        ? $organizationOverview['stages']
        : [];

    $check(
        ($organizationMetrics[
            'branches_active'
        ] ?? 0) === 1
        && ($organizationMetrics[
            'departments_active'
        ] ?? 0) === 2
        && ($organizationMetrics[
            'job_titles_active'
        ] ?? 0) === 1
        && ($organizationMetrics[
            'positions_total'
        ] ?? 0) === 1
        && ($organizationMetrics[
            'active_employees'
        ] ?? 0) === 1
        && ($organizationMetrics[
            'assigned_employees'
        ] ?? 0) === 1,
        'Organization readiness metrics are isolated to Tenant A'
    );

    $check(
        count($organizationStages) === 7
        && ($organizationOverview[
            'progress'
        ] ?? 0) === 100
        && ($organizationOverview[
            'readinessLabel'
        ] ?? '') === 'Operationally ready'
        && ($organizationOverview[
            'nextAction'
        ] ?? null) === null,
        'Organization setup service evaluates the complete guided readiness sequence'
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

    $attendanceSelfService =
        new AttendanceSelfServiceService();
    $personalAttendance =
        $attendanceSelfService->workspace(
            910004,
            '2026-07'
        );
    $personalCheckIn =
        $attendanceSelfService->checkIn(
            910004
        );
    $duplicateCheckIn =
        $attendanceSelfService->checkIn(
            910004
        );
    $personalCheckOut =
        $attendanceSelfService->checkOut(
            910004
        );
    $managerAttendance =
        $attendanceSelfService->teamWorkspace(
            $tenantAActorId,
            '2026-07'
        );
    $employeeManagerAttendance =
        $attendanceSelfService->teamWorkspace(
            910004,
            '2026-07'
        );
    $managerAttendanceNames = array_map(
        static fn (array $person): string =>
            (string) (
                $person['displayName'] ?? ''
            ),
        $managerAttendance['people'] ?? []
    );

    $check(
        (
            $personalAttendance['employee'][
                'employee_id'
            ] ?? null
        ) === 920001
        && (
            $personalAttendance['summary'][
                'recorded'
            ] ?? 0
        ) >= 1,
        'Personal attendance resolves only the signed-in linked employee'
    );
    $check(
        $personalCheckIn['successful'] === true
        && $duplicateCheckIn['successful']
            === false
        && $personalCheckOut['successful']
            === true,
        'Employee self-service check-in and check-out enforce one daily sequence'
    );
    $check(
        in_array(
            'Alice TenantA',
            $managerAttendanceNames,
            true
        )
        && !in_array(
            'Bob TenantB',
            $managerAttendanceNames,
            true
        )
        && (
            $employeeManagerAttendance['people']
            ?? null
        ) === [],
        'Manager attendance exposes direct reports without cross-company or company-directory leakage'
    );

    $selfAttendanceAudit = db()->prepare(
        'SELECT COUNT(*)
         FROM audit_logs
         WHERE company_id = :company_id
           AND module = :module
           AND table_name = :table_name
           AND action IN (
               \'SELF_CHECK_IN\',
               \'SELF_CHECK_OUT\'
           )'
    );
    $selfAttendanceAudit->execute([
        'company_id' => $tenantACompanyId,
        'module' => 'attendance',
        'table_name' => 'attendance_records',
    ]);
    $check(
        (int) $selfAttendanceAudit
            ->fetchColumn() === 2,
        'Personal attendance actions create tenant-scoped audit events'
    );

    $attendanceReminders =
        new AttendanceReminderService();
    $invalidReminder =
        $attendanceReminders->save(
            [
                'timezone' => 'Invalid/Timezone',
                'workdays' => [],
                'check_in_enabled' => false,
                'check_in_time' => '25:00',
                'check_out_enabled' => false,
                'check_out_time' => '17:30',
                'reminder_lead_minutes' => '7',
                'browser_notifications_enabled' =>
                    false,
            ],
            910004
        );
    $foreignReminder =
        $attendanceReminders->save(
            [
                'timezone' => 'Africa/Nairobi',
                'workdays' => ['1'],
                'check_in_enabled' => true,
                'check_in_time' => '08:30',
                'check_out_enabled' => false,
                'check_out_time' => '17:30',
                'reminder_lead_minutes' => '10',
                'browser_notifications_enabled' =>
                    false,
            ],
            910002
        );
    $savedReminder =
        $attendanceReminders->save(
            [
                'timezone' => 'Africa/Nairobi',
                'workdays' => [
                    '1',
                    '2',
                    '3',
                    '4',
                    '5',
                    '6',
                    '7',
                ],
                'check_in_enabled' => true,
                'check_in_time' => '08:30',
                'check_out_enabled' => true,
                'check_out_time' => '17:30',
                'reminder_lead_minutes' => '15',
                'browser_notifications_enabled' =>
                    true,
            ],
            910004
        );
    $reminderWorkspace =
        $attendanceReminders->workspace(
            910004,
            [
                'profileRequired' => false,
                'today' => null,
            ],
            new \DateTimeImmutable(
                '2026-07-30 08:20:00',
                new \DateTimeZone(
                    'Africa/Nairobi'
                )
            )
        );

    $check(
        $invalidReminder['successful'] === false
        && isset(
            $invalidReminder['errors']['timezone'],
            $invalidReminder['errors']['workdays'],
            $invalidReminder['errors']['reminders'],
            $invalidReminder['errors'][
                'check_in_time'
            ],
            $invalidReminder['errors'][
                'reminder_lead_minutes'
            ]
        ),
        'Attendance reminder settings reject invalid personal schedules'
    );
    $check(
        $foreignReminder['successful'] === false
        && isset(
            $foreignReminder['errors']['form']
        ),
        'Attendance reminders cannot be configured for a user outside the active company'
    );
    $check(
        $savedReminder['successful'] === true
        && (
            $reminderWorkspace['settings'][
                'checkInTime'
            ] ?? null
        ) === '08:30'
        && (
            $reminderWorkspace['settings'][
                'browserEnabled'
            ] ?? null
        ) === true
        && (
            $reminderWorkspace['notification'][
                'kind'
            ] ?? null
        ) === 'check-in'
        && (
            $reminderWorkspace['notification'][
                'status'
            ] ?? null
        ) === 'due',
        'Employee-owned attendance reminders use the saved timezone, schedule and delivery preference'
    );

    $attendanceReminderAudit = db()->prepare(
        'SELECT COUNT(*)
         FROM audit_logs
         WHERE company_id = :company_id
           AND user_id = :user_id
           AND action = :action
           AND module = :module
           AND table_name = :table_name'
    );
    $attendanceReminderAudit->execute([
        'company_id' => $tenantACompanyId,
        'user_id' => 910004,
        'action' =>
            'UPDATE_ATTENDANCE_REMINDERS',
        'module' => 'attendance',
        'table_name' =>
            'attendance_user_reminders',
    ]);
    $check(
        (int) $attendanceReminderAudit
            ->fetchColumn() === 1,
        'Personal attendance reminder changes create a tenant-scoped audit event'
    );

    $workforceCalendars =
        new WorkforceCalendarService();
    $createdCalendar = $workforceCalendars->create(
        [
            'code' => 'KE_NAIROBI',
            'name' => 'Kenya · Nairobi office',
            'timezone' => 'Africa/Nairobi',
            'country_code' => 'KE',
            'subdivision_code' => 'KE-30',
            'week_start' => '1',
            'is_default' => false,
        ],
        $tenantAActorId
    );
    $calendarId = (int) (
        $createdCalendar['calendarId'] ?? 0
    );
    $savedWeek = $workforceCalendars->saveWeek(
        $calendarId,
        [
            1 => [
                'working_day' => true,
                'start_time' => '08:30',
                'end_time' => '17:30',
                'break_minutes' => '60',
            ],
            2 => [
                'working_day' => true,
                'start_time' => '08:30',
                'end_time' => '17:30',
                'break_minutes' => '60',
            ],
            3 => [
                'working_day' => true,
                'start_time' => '08:30',
                'end_time' => '17:30',
                'break_minutes' => '60',
            ],
            4 => [
                'working_day' => true,
                'start_time' => '08:30',
                'end_time' => '17:30',
                'break_minutes' => '60',
            ],
            5 => [
                'working_day' => true,
                'start_time' => '08:30',
                'end_time' => '17:30',
                'break_minutes' => '60',
            ],
            6 => [
                'working_day' => false,
                'start_time' => '',
                'end_time' => '',
                'break_minutes' => '0',
            ],
            7 => [
                'working_day' => false,
                'start_time' => '',
                'end_time' => '',
                'break_minutes' => '0',
            ],
        ],
        $tenantAActorId
    );
    $addedHoliday = $workforceCalendars->addHoliday(
        $calendarId,
        [
            'holiday_date' => '2026-07-30',
            'name' => 'Integration Public Holiday',
            'holiday_type' => 'public',
            'day_portion' => 'full',
            'observed' => true,
            'description' =>
                'International calendar integration fixture',
        ],
        $tenantAActorId
    );
    $assignedCalendar = $workforceCalendars->assign(
        [
            'employee_id' => '920001',
            'calendar_id' => (string) $calendarId,
            'effective_from' => '2026-01-01',
            'effective_to' => '',
        ],
        $tenantAActorId
    );
    $overlappingCalendar =
        $workforceCalendars->assign(
            [
                'employee_id' => '920001',
                'calendar_id' =>
                    (string) $calendarId,
                'effective_from' => '2026-06-01',
                'effective_to' => '2026-12-31',
            ],
            $tenantAActorId
        );
    $holidayContext =
        $workforceCalendars->contextForUser(
            910004,
            '2026-07-30'
        );
    $calendarWorkspace =
        $workforceCalendars->workspace(
            999999,
            2026
        );
    $holidayReminder =
        $attendanceReminders->workspace(
            910004,
            [
                'profileRequired' => false,
                'today' => null,
            ],
            new \DateTimeImmutable(
                '2026-07-30 08:20:00',
                new \DateTimeZone(
                    'Africa/Nairobi'
                )
            )
        );

    $check(
        $createdCalendar['successful'] === true
        && $calendarId > 0
        && !empty(
            $calendarWorkspace['selected'][
                'is_default'
            ]
        )
        && (int) (
            $calendarWorkspace['selected'][
                'calendar_id'
            ] ?? 0
        ) === $calendarId
        && $savedWeek['successful'] === true
        && $addedHoliday['successful'] === true
        && $assignedCalendar['successful'] === true,
        'Company administrator can configure a safe default international calendar, holiday and employee schedule'
    );
    $check(
        $overlappingCalendar['successful'] === false
        && isset(
            $overlappingCalendar['errors'][
                'effective_from'
            ]
        ),
        'Effective-dated work schedules reject overlapping employee assignments'
    );
    $check(
        ($holidayContext['calendarName'] ?? null)
            === 'Kenya · Nairobi office'
        && (
            $holidayContext['holiday']['name']
            ?? null
        ) === 'Integration Public Holiday'
        && (
            $holidayReminder['notification'][
                'status'
            ] ?? null
        ) === 'holiday',
        'Assigned calendars suppress personal attendance reminders on full-day holidays'
    );

    $attendanceNotifications =
        new AttendanceNotificationService();
    $queueResult =
        $attendanceNotifications->queueDue(
            new \DateTimeImmutable(
                '2026-07-31 05:20:00',
                new \DateTimeZone('UTC')
            )
        );
    $notificationInbox =
        $attendanceNotifications->inbox(910004);
    $notificationId = (int) (
        $notificationInbox[0]['notification_id']
        ?? 0
    );
    $foreignRead =
        $attendanceNotifications->markRead(
            910002,
            $notificationId
        );
    $ownedRead =
        $attendanceNotifications->markRead(
            910004,
            $notificationId
        );

    $check(
        $queueResult['queued'] === 1
        && $notificationId > 0
        && (
            $notificationInbox[0][
                'notification_type'
            ] ?? null
        ) === 'check_in',
        'Server dispatcher creates one durable deduplicated attendance notification'
    );
    $check(
        $foreignRead === false
        && $ownedRead === true,
        'Attendance notification inbox enforces company and user ownership'
    );

    $overnightSettings =
        $attendanceReminders->save(
            [
                'timezone' => 'Africa/Nairobi',
                'workdays' => ['5'],
                'check_in_enabled' => true,
                'check_in_time' => '22:00',
                'check_out_enabled' => true,
                'check_out_time' => '01:00',
                'reminder_lead_minutes' => '15',
                'browser_enabled' => true,
            ],
            910004
        );
    RepositoryFactory::attendance()->save(
        $tenantACompanyId,
        920001,
        '2026-07-31',
        [
            'check_in_at' =>
                '2026-07-31 22:00:00',
            'check_out_at' => null,
            'attendance_status' => 'present',
            'work_minutes' => 0,
            'source' => 'system',
            'notes' =>
                'Overnight notification integration fixture',
        ],
        $tenantAActorId
    );
    $overnightQueue =
        $attendanceNotifications->queueDue(
            new \DateTimeImmutable(
                '2026-07-31 21:50:00',
                new \DateTimeZone('UTC')
            )
        );
    $overnightInbox =
        $attendanceNotifications->inbox(910004);
    $overnightNotification =
        $overnightInbox[0] ?? [];

    $check(
        $overnightSettings['successful'] === true
        && $overnightQueue['queued'] === 1
        && (
            $overnightNotification[
                'notification_type'
            ] ?? null
        ) === 'check_out'
        && (
            $overnightNotification[
                'local_date'
            ] ?? null
        ) === '2026-07-31'
        && (
            $overnightNotification[
                'scheduled_for'
            ] ?? null
        ) === '2026-07-31 22:00:00',
        'Server dispatcher carries overnight check-out reminders into the next local day'
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
    $leaveDecision = $leaveManagement
        ->decideForActor(
        $leaveRequestId,
        'approved',
        'Approved by integration workflow',
        $tenantAActorId,
        false,
        true
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

    $leavePolicies = new LeavePolicyService();
    $policyListing = $leavePolicies->listing();
    $policyIds = array_map(
        static fn (array $policy): int =>
            (int) (
                $policy['leave_type_id'] ?? 0
            ),
        $policyListing['policies'] ?? []
    );
    $foreignPolicy = $leavePolicies->form(970002);
    $createdPolicy = $leavePolicies->create(
        [
            'code' => 'WELLNESS',
            'name' => 'Wellness Leave',
            'annual_entitlement' => '3.50',
            'requires_approval' => false,
            'active' => true,
        ],
        $tenantAActorId
    );
    $createdPolicyId = (int) (
        $createdPolicy['leaveTypeId'] ?? 0
    );
    $duplicatePolicy = $leavePolicies->create(
        [
            'code' => 'WELLNESS',
            'name' => 'Alternative Wellness Leave',
            'annual_entitlement' => '4',
            'requires_approval' => true,
            'active' => true,
        ],
        $tenantAActorId
    );

    $check(
        in_array(970001, $policyIds, true)
        && !in_array(970002, $policyIds, true)
        && $foreignPolicy === null,
        'Leave-policy catalogue and lookup enforce tenant isolation'
    );
    $check(
        $createdPolicy['successful'] === true
        && $createdPolicyId > 0
        && $duplicatePolicy['successful'] === false
        && isset(
            $duplicatePolicy['errors']['code']
        ),
        'Leave policies validate unique company codes'
    );

    $automaticLeave = $leaveManagement->create(
        [
            'employee_id' => '920001',
            'leave_type_id' =>
                (string) $createdPolicyId,
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-06',
            'reason' =>
                'Automatic policy integration request',
        ],
        $tenantAActorId
    );
    $automaticLeaveStatement = db()->prepare(
        'SELECT
            request_status,
            decision_note,
            decided_by,
            decided_at
         FROM hr_leave_requests
         WHERE company_id = :company_id
           AND leave_request_id =
                :leave_request_id'
    );
    $automaticLeaveStatement->execute([
        'company_id' => $tenantACompanyId,
        'leave_request_id' => (int) (
            $automaticLeave['leaveRequestId']
                ?? 0
        ),
    ]);
    $automaticLeaveRecord =
        $automaticLeaveStatement->fetch(
            \PDO::FETCH_ASSOC
        );

    $check(
        $automaticLeave['successful'] === true
        && ($automaticLeave['status'] ?? null)
            === 'approved'
        && is_array($automaticLeaveRecord)
        && (
            $automaticLeaveRecord[
                'request_status'
            ] ?? null
        ) === 'approved'
        && (int) (
            $automaticLeaveRecord['decided_by']
                ?? 0
        ) === $tenantAActorId
        && !empty(
            $automaticLeaveRecord['decided_at']
        ),
        'Policies without approval automatically approve and record a decision'
    );

    $updatedPolicy = $leavePolicies->update(
        $createdPolicyId,
        [
            'code' => 'WELLNESS',
            'name' => 'Wellness and Recovery Leave',
            'annual_entitlement' => '4.00',
            'requires_approval' => false,
            'active' => false,
        ],
        $tenantAActorId
    );
    $updatedPolicyRecord =
        $leavePolicies->form($createdPolicyId);

    $check(
        $updatedPolicy['successful'] === true
        && !empty($updatedPolicy['changed'])
        && is_array($updatedPolicyRecord)
        && (
            $updatedPolicyRecord['name']
                ?? null
        ) === 'Wellness and Recovery Leave'
        && empty($updatedPolicyRecord['active']),
        'Used leave policies can be updated and safely deactivated'
    );

    $pendingPolicyLeave = $leaveManagement->create(
        [
            'employee_id' => '920001',
            'leave_type_id' => '970001',
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-02',
            'reason' =>
                'Pending policy protection request',
        ],
        $tenantAActorId
    );
    $blockedPolicyDeactivation =
        $leavePolicies->update(
            970001,
            [
                'code' => 'ANNUAL',
                'name' => 'Annual Leave',
                'annual_entitlement' => '21.00',
                'requires_approval' => true,
                'active' => false,
            ],
            $tenantAActorId
        );

    $check(
        $pendingPolicyLeave['successful'] === true
        && ($pendingPolicyLeave['status'] ?? null)
            === 'pending'
        && $blockedPolicyDeactivation[
            'successful'
        ] === false
        && isset(
            $blockedPolicyDeactivation[
                'errors'
            ]['active']
        ),
        'Leave policy deactivation is blocked while requests await approval'
    );

    $policyAuditStatement = db()->prepare(
        'SELECT COUNT(*)
         FROM audit_logs
         WHERE company_id = :company_id
           AND module = :module
           AND table_name = :table_name
           AND record_id = :record_id
           AND action IN (
               \'CREATE_LEAVE_POLICY\',
               \'UPDATE_LEAVE_POLICY\'
           )'
    );
    $policyAuditStatement->execute([
        'company_id' => $tenantACompanyId,
        'module' => 'hr',
        'table_name' => 'hr_leave_types',
        'record_id' => (string) $createdPolicyId,
    ]);

    $check(
        (int) $policyAuditStatement
            ->fetchColumn() === 2,
        'Leave policy changes create tenant-scoped audit events'
    );

    $twoStagePolicy = $leavePolicies->create(
        [
            'code' => 'DUAL_APPROVAL',
            'name' => 'Dual Approval Leave',
            'annual_entitlement' => '2.00',
            'approval_workflow' =>
                'manager_then_hr',
            'hr_approver_user_id' =>
                (string) $tenantAActorId,
            'active' => true,
        ],
        $tenantAActorId
    );
    $twoStagePolicyId = (int) (
        $twoStagePolicy['leaveTypeId'] ?? 0
    );
    $twoStageRequest = $leaveManagement
        ->createForActor(
            [
                'leave_type_id' =>
                    (string) $twoStagePolicyId,
                'start_date' => '2026-11-09',
                'end_date' => '2026-11-10',
                'reason' =>
                    'Sequential approval integration request',
            ],
            910004,
            false,
            true
        );
    $twoStageRequestId = (int) (
        $twoStageRequest['leaveRequestId'] ?? 0
    );
    $managerStageDecision = $leaveManagement
        ->decideForActor(
            $twoStageRequestId,
            'approved',
            'Manager stage approved',
            $tenantAActorId,
            false,
            true
        );
    $pendingTwoStageWorkspace =
        $leaveManagement->workspace(
            $tenantAActorId,
            'pending',
            true,
            false,
            true,
            false,
            true
        );
    $pendingTwoStage = null;

    foreach (
        $pendingTwoStageWorkspace['requests'] ?? []
        as $pendingRequest
    ) {
        if (
            (int) (
                $pendingRequest['leave_request_id']
                ?? 0
            ) === $twoStageRequestId
        ) {
            $pendingTwoStage = $pendingRequest;
            break;
        }
    }

    $check(
        $twoStagePolicy['successful'] === true
        && $twoStageRequest['successful'] === true
        && count(
            $twoStageRequest['approvers'] ?? []
        ) === 2
        && $managerStageDecision['successful']
            === true
        && empty(
            $managerStageDecision['finalized']
        )
        && (
            $managerStageDecision['nextStage']
            ?? null
        ) === 'hr'
        && is_array($pendingTwoStage)
        && (
            $pendingTwoStage[
                'currentApprovalStage'
            ] ?? null
        ) === 'hr'
        && (
            $pendingTwoStage[
                'currentApproverUserId'
            ] ?? null
        ) === $tenantAActorId
        && (
            $pendingTwoStage[
                'approvalProgressLabel'
            ] ?? null
        ) ===
            'Manager approved — waiting for HR'
        && (
            $pendingTwoStage[
                'approvalStages'
            ][0]['statusLabel'] ?? null
        ) === 'Approved'
        && (
            $pendingTwoStage[
                'approvalStages'
            ][1]['statusLabel'] ?? null
        ) === 'In review',
        'Two-stage leave waits for named HR approval after manager approval'
    );

    $hrStageDecision = $leaveManagement
        ->decideForActor(
            $twoStageRequestId,
            'approved',
            'HR stage approved',
            $tenantAActorId,
            true,
            false
        );
    $twoStageRecordStatement = db()->prepare(
        'SELECT request_status
         FROM hr_leave_requests
         WHERE company_id = :company_id
           AND leave_request_id =
                :leave_request_id'
    );
    $twoStageRecordStatement->execute([
        'company_id' => $tenantACompanyId,
        'leave_request_id' => $twoStageRequestId,
    ]);
    $twoStageStageStatement = db()->prepare(
        'SELECT
            approval_stage,
            approval_status
         FROM hr_leave_request_approvals
         WHERE company_id = :company_id
           AND leave_request_id =
                :leave_request_id
         ORDER BY approval_sequence'
    );
    $twoStageStageStatement->execute([
        'company_id' => $tenantACompanyId,
        'leave_request_id' => $twoStageRequestId,
    ]);
    $twoStageStages = $twoStageStageStatement
        ->fetchAll(\PDO::FETCH_ASSOC);

    $check(
        $hrStageDecision['successful'] === true
        && !empty($hrStageDecision['finalized'])
        && $hrStageDecision['status']
            === 'approved'
        && $twoStageRecordStatement
            ->fetchColumn() === 'approved'
        && count($twoStageStages) === 2
        && ($twoStageStages[0][
            'approval_status'
        ] ?? null) === 'approved'
        && ($twoStageStages[1][
            'approval_status'
        ] ?? null) === 'approved',
        'HR approval finalizes the request after every required stage'
    );

    $leaveBalances =
        new LeaveBalanceManagementService();
    $foreignBalanceWorkspace =
        $leaveBalances->workspace(
            920002,
            2026,
            970001
        );
    $foreignAllocation =
        $leaveBalances->saveAllocation(
            [
                'employee_id' => '920002',
                'leave_type_id' => '970001',
                'year' => '2026',
                'entitlement_days' => '25',
                'carry_over_days' => '3',
                'notes' =>
                    'Cross-company allocation attempt',
            ],
            $tenantAActorId
        );
    $allocationResult =
        $leaveBalances->saveAllocation(
            [
                'employee_id' => '920001',
                'leave_type_id' => '970001',
                'year' => '2026',
                'entitlement_days' => '25',
                'carry_over_days' => '3',
                'notes' =>
                    'Approved annual carry-over',
            ],
            $tenantAActorId
        );
    $unchangedAllocation =
        $leaveBalances->saveAllocation(
            [
                'employee_id' => '920001',
                'leave_type_id' => '970001',
                'year' => '2026',
                'entitlement_days' => '25.00',
                'carry_over_days' => '3.00',
                'notes' =>
                    'Approved annual carry-over',
            ],
            $tenantAActorId
        );
    $creditResult =
        $leaveBalances->addAdjustment(
            [
                'employee_id' => '920001',
                'leave_type_id' => '970001',
                'year' => '2026',
                'adjustment_type' => 'credit',
                'days' => '2',
                'effective_date' => '2026-06-30',
                'reason' =>
                    'Approved service award credit',
            ],
            $tenantAActorId
        );
    $debitResult =
        $leaveBalances->addAdjustment(
            [
                'employee_id' => '920001',
                'leave_type_id' => '970001',
                'year' => '2026',
                'adjustment_type' => 'debit',
                'days' => '1',
                'effective_date' => '2026-07-01',
                'reason' =>
                    'Correction of duplicate allocation',
            ],
            $tenantAActorId
        );
    $balanceWorkspace =
        $leaveBalances->workspace(
            920001,
            2026,
            970001
        );
    $annualBalance = null;

    foreach (
        $balanceWorkspace['balances'] ?? []
        as $balanceRecord
    ) {
        if (
            (int) (
                $balanceRecord['leave_type_id'] ?? 0
            ) === 970001
        ) {
            $annualBalance = $balanceRecord;

            break;
        }
    }

    $allocationCountStatement = db()->prepare(
        'SELECT COUNT(*)
         FROM hr_leave_allocations
         WHERE company_id = :company_id
           AND employee_id = :employee_id
           AND leave_type_id = :leave_type_id
           AND allocation_year = :allocation_year'
    );
    $allocationCountStatement->execute([
        'company_id' => $tenantACompanyId,
        'employee_id' => 920001,
        'leave_type_id' => 970001,
        'allocation_year' => 2026,
    ]);
    $balanceAuditStatement = db()->prepare(
        'SELECT COUNT(*)
         FROM audit_logs
         WHERE company_id = :company_id
           AND module = :module
           AND (
                (
                    table_name =
                        \'hr_leave_allocations\'
                    AND action =
                        \'CREATE_LEAVE_ALLOCATION\'
                )
                OR (
                    table_name =
                        \'hr_leave_balance_adjustments\'
                    AND action =
                        \'ADJUST_LEAVE_BALANCE\'
                )
           )'
    );
    $balanceAuditStatement->execute([
        'company_id' => $tenantACompanyId,
        'module' => 'hr',
    ]);

    $check(
        !empty($foreignBalanceWorkspace['notFound'])
        && $foreignAllocation['successful'] === false
        && isset(
            $foreignAllocation['errors']['employee_id']
        ),
        'Leave-balance management rejects foreign-company employees'
    );
    $check(
        $allocationResult['successful'] === true
        && !empty($allocationResult['changed'])
        && $unchangedAllocation['successful']
            === true
        && empty($unchangedAllocation['changed'])
        && (int) $allocationCountStatement
            ->fetchColumn() === 1,
        'Annual allocation upserts once and detects unchanged records'
    );
    $check(
        $creditResult['successful'] === true
        && $debitResult['successful'] === true
        && is_array($annualBalance)
        && (
            $annualBalance['availableDays'] ?? null
        ) === '29.00'
        && (
            $annualBalance['adjustmentDays'] ?? null
        ) === '1.00'
        && (
            $annualBalance['usedDays'] ?? null
        ) === '5.00'
        && (
            $annualBalance['remainingDays'] ?? null
        ) === '24.00',
        'Credits, debits and approved leave produce the calculated annual balance'
    );
    $check(
        count(
            $balanceWorkspace['adjustments'] ?? []
        ) === 2
        && (int) $balanceAuditStatement
            ->fetchColumn() === 3,
        'Balance ledger and audit events are tenant-scoped and complete'
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

    $managerTeamWorkspace =
        (new ManagerWorkspaceService())->workspace(
            $tenantAActorId,
            true
        );
    $employeeTeamWorkspace =
        (new ManagerWorkspaceService())->workspace(
            910004,
            true
        );
    $managerReportNames = array_map(
        static fn (array $report): string =>
            (string) (
                $report['displayName'] ?? ''
            ),
        $managerTeamWorkspace['reports'] ?? []
    );
    $employeeBalanceNames = array_map(
        static fn (array $balance): string =>
            (string) ($balance['name'] ?? ''),
        $employeeTeamWorkspace['balances'] ?? []
    );

    $check(
        in_array(
            'Alice TenantA',
            $managerReportNames,
            true
        )
        && !in_array(
            'Bob TenantB',
            $managerReportNames,
            true
        )
        && (
            $managerTeamWorkspace['summary'][
                'directReports'
            ] ?? null
        ) === count($managerReportNames)
        && count($managerReportNames) >= 1,
        'Manager workspace returns only same-company direct reports'
    );
    $check(
        ($employeeTeamWorkspace['reports'] ?? null)
            === []
        && in_array(
            'Annual Leave',
            $employeeBalanceNames,
            true
        )
        && (
            $employeeTeamWorkspace['reporting'][
                'manager_display_name'
            ] ?? null
        ) === 'Test Tenant A Administrator',
        'Employee workspace shows personal balances and reporting line without company directory access'
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

    $legacyManagerUpdate = (
        new UserUpdateService()
    )->update(
        $tenantATargetUserId,
        [
            'username' =>
                'test_tenant_a_target',
            'email' =>
                'tenant-a-target@example.test',
            'display_name' =>
                'Test Tenant A Target',
            'active' => true,
            'manager_user_id' =>
                $tenantAActorId,
            'role_ids' =>
                $targetRoleIdsBefore,
        ],
        $tenantAActorId
    );
    $legacyManagerStatement = db()->prepare(
        'SELECT manager_user_id
         FROM company_users
         WHERE company_id = :company_id
           AND user_id = :user_id'
    );
    $legacyManagerStatement->execute([
        'company_id' => $tenantACompanyId,
        'user_id' => $tenantATargetUserId,
    ]);

    $check(
        $legacyManagerUpdate['successful']
            === true
        && (int) $legacyManagerStatement
            ->fetchColumn() === $tenantAActorId,
        'Existing tenant users can receive a reporting manager through the edit workflow'
    );

    $sample = (
        new DevelopmentSampleCompanyService()
    )->create((int) $userId);
    $sampleCompanyId = (int) (
        $sample['companyId'] ?? 0
    );
    $sampleTopology = db()->prepare(
        'SELECT
            companies.approval_status,
            companies.active,
            employee_user.user_id AS employee_user_id,
            employee_membership.manager_user_id,
            owner_user.user_id AS owner_user_id,
            employee_profile.employee_id,
            employee_profile.manager_employee_id,
            owner_profile.employee_id
                AS owner_employee_id
         FROM companies
         INNER JOIN users owner_user
            ON owner_user.username =
                :owner_username
         INNER JOIN users employee_user
            ON employee_user.username =
                :employee_username
         INNER JOIN company_users
            AS employee_membership
            ON employee_membership.company_id =
                companies.company_id
           AND employee_membership.user_id =
                employee_user.user_id
         LEFT JOIN hr_employees owner_profile
            ON owner_profile.company_id =
                companies.company_id
           AND owner_profile.user_id =
                owner_user.user_id
         INNER JOIN hr_employees employee_profile
            ON employee_profile.company_id =
                companies.company_id
           AND employee_profile.user_id =
                employee_user.user_id
         WHERE companies.company_id = :company_id'
    );
    $sampleTopology->execute([
        'owner_username' =>
            DevelopmentSampleCompanyService::
                OWNER_USERNAME,
        'employee_username' =>
            DevelopmentSampleCompanyService::
                EMPLOYEE_USERNAME,
        'company_id' => $sampleCompanyId,
    ]);
    $sampleTopology = $sampleTopology->fetch(
        \PDO::FETCH_ASSOC
    );
    $samplePermissions = is_array($sampleTopology)
        ? (new CompanyMembership())->permissionCodes(
            (int) $sampleTopology[
                'employee_user_id'
            ],
            $sampleCompanyId
        )
        : [];
    $sampleOwnerPermissions =
        is_array($sampleTopology)
            ? (
                new CompanyMembership()
            )->permissionCodes(
                (int) $sampleTopology[
                    'owner_user_id'
                ],
                $sampleCompanyId
            )
            : [];

    $check(
        $sampleCompanyId > 0
        && is_array($sampleTopology)
        && $sampleTopology['approval_status']
            === 'approved'
        && !empty($sampleTopology['active'])
        && (int) $sampleTopology[
            'manager_user_id'
        ] === (int) $sampleTopology[
            'owner_user_id'
        ]
        && $sampleTopology[
            'manager_employee_id'
        ] === null
        && $sampleTopology[
            'owner_employee_id'
        ] === null,
        'Development sample provisioning keeps the owner administrative while linking the employee reporting line'
    );

    $ownerPersonalPermissions = [
        'hr.leave.self.view',
        'hr.leave.self.request',
        'attendance.self.view',
        'attendance.self.record',
    ];
    $ownerAdministrativePermissions = [
        'dashboard.view',
        'administration.users.manage',
        'administration.roles.manage',
        'audit.logs.view',
        'hr.leave.team.approve',
        'attendance.team.view',
    ];

    $check(
        array_intersect(
            $ownerPersonalPermissions,
            $sampleOwnerPermissions
        ) === []
        && array_diff(
            $ownerAdministrativePermissions,
            $sampleOwnerPermissions
        ) === [],
        'Development sample owner receives administration and team oversight without employee self service'
    );

    $check(
        in_array(
            'hr.leave.self.request',
            $samplePermissions,
            true
        )
        && in_array(
            'attendance.self.record',
            $samplePermissions,
            true
        ),
        'Development sample employee receives current self-service permissions'
    );

    $sampleLogin = (new AuthService())->attempt(
        (string) $sample['employeeUsername'],
        (string) $sample[
            'employeeTemporaryPassword'
        ]
    );

    $check(
        $sampleLogin['successful'] === true
        && !empty(
            $_SESSION['auth'][
                'must_change_password'
            ]
        )
        && (
            $_SESSION['auth']['company']['company_id']
            ?? null
        ) === $sampleCompanyId,
        'Development sample employee can authenticate into only the sample company and must change password'
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
