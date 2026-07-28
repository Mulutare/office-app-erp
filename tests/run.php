<?php

declare(strict_types=1);

require_once __DIR__
    . '/../app/helpers/bootstrap.php';

use App\Database\ConnectionManager;
use App\Models\CompanyMembership;
use App\Services\AuthService;

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
