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
use App\Services\DashboardService;
use App\Services\PlatformAdministratorProtectionService;
use App\Services\UserAccountStatusService;
use App\Services\UserAccountUnlockService;
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
        && count($oracleMigrationFiles) === 6,
        'Oracle migration catalog contains six valid definitions'
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
        $oracleTableCount === 17
        && $oracleIdentityCount === 11,
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
        $tableCount === 17,
        'All 17 application tables were created'
    );

    $foreignKeyCount = (int) db()
        ->query(
            'SELECT COUNT(*)
             FROM information_schema.referential_constraints
             WHERE constraint_schema = DATABASE()'
        )
        ->fetchColumn();

    $check(
        $foreignKeyCount === 43,
        'All 43 foreign-key relationships were created'
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
