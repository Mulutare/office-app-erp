<?php

declare(strict_types=1);

use App\Repositories\RepositoryFactory;

require_once __DIR__
    . '/../app/helpers/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * @return array<string, string|false>
 */
$readOptions = static function (): array {
    $options = getopt(
        '',
        [
            'username:',
            'email:',
            'name:',
        ]
    );

    return is_array($options) ? $options : [];
};

$optionString = static function (
    array $options,
    string $name
): string {
    $value = $options[$name] ?? '';

    return is_string($value)
        ? trim($value)
        : '';
};

$environmentValue = static function (
    string $name
): string {
    $value = getenv($name);

    return is_string($value)
        ? $value
        : '';
};

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$options = $readOptions();
$username = strtolower(
    $optionString($options, 'username')
);
$email = strtolower(
    $optionString($options, 'email')
);
$displayName = $optionString($options, 'name');
$password = $environmentValue(
    'OFFICEAPP_INITIAL_ADMIN_PASSWORD'
);

if (
    !preg_match(
        '/^[a-z][a-z0-9._-]{2,49}$/',
        $username
    )
) {
    $fail(
        'The username must contain 3-50 lowercase'
        . ' letters, numbers, dots, hyphens or underscores'
        . ' and begin with a letter.'
    );
}

if (
    !filter_var($email, FILTER_VALIDATE_EMAIL)
    || strlen($email) > 190
) {
    $fail(
        'A valid administrator email is required.'
    );
}

if (
    mb_strlen($displayName) < 2
    || mb_strlen($displayName) > 150
) {
    $fail(
        'The administrator name must contain 2-150 characters.'
    );
}

if (
    strlen($password) < 16
    || !preg_match('/[A-Z]/', $password)
    || !preg_match('/[a-z]/', $password)
    || !preg_match('/[0-9]/', $password)
    || !preg_match('/[^A-Za-z0-9]/', $password)
) {
    $fail(
        'OFFICEAPP_INITIAL_ADMIN_PASSWORD must contain'
        . ' at least 16 characters including upper and lower'
        . ' case letters, a number and a special character.'
    );
}

$platformCount = (int) db()->query(
    'SELECT COUNT(*)
     FROM users
     WHERE is_platform_admin = 1
       AND deleted_at IS NULL'
)->fetchColumn();

if ($platformCount > 0) {
    $fail(
        'A platform administrator already exists.'
        . ' Use the protected administration workflow instead.'
    );
}

$duplicateStatement = db()->prepare(
    'SELECT COUNT(*)
     FROM users
     WHERE (
        username = :username
        OR email = :email
     )
       AND deleted_at IS NULL'
);
$duplicateStatement->execute([
    'username' => $username,
    'email' => $email,
]);

if ((int) $duplicateStatement->fetchColumn() > 0) {
    $fail(
        'The username or email is already assigned.'
    );
}

$firstRowClause = databaseDriver()
    ->dialect()
    ->firstRowClause();
$contextStatement = db()->query(
    'SELECT
        companies.company_id,
        roles.role_id
     FROM companies
     CROSS JOIN roles
     WHERE companies.code = \'default\'
       AND companies.deleted_at IS NULL
       AND roles.code = \'system_administrator\'
       AND roles.active = 1
     ' . $firstRowClause
);
$context = $contextStatement->fetch(
    PDO::FETCH_ASSOC
);

if (!is_array($context)) {
    $fail(
        'The vendor company or system administrator role'
        . ' is missing. Run migrations and reference-data sync first.'
    );
}

$passwordHash = password_hash(
    $password,
    PASSWORD_DEFAULT
);

if (!is_string($passwordHash)) {
    $fail(
        'Unable to securely hash the administrator password.'
    );
}

$companyId = (int) $context['company_id'];
$roleId = (int) $context['role_id'];

try {
    db()->beginTransaction();

    $insertUser = db()->prepare(
        'INSERT INTO users
            (
                username,
                email,
                password_hash,
                display_name,
                is_platform_admin,
                active,
                must_change_password,
                failed_login_count
            )
         VALUES
            (
                :username,
                :email,
                :password_hash,
                :display_name,
                1,
                1,
                1,
                0
            )'
    );
    $insertUser->execute([
        'username' => $username,
        'email' => $email,
        'password_hash' => $passwordHash,
        'display_name' => $displayName,
    ]);

    $userStatement = db()->prepare(
        'SELECT user_id
         FROM users
         WHERE username = :username
           AND deleted_at IS NULL
         ' . $firstRowClause
    );
    $userStatement->execute([
        'username' => $username,
    ]);
    $userId = (int) $userStatement->fetchColumn();

    if ($userId < 1) {
        throw new RuntimeException(
            'The administrator identity could not be resolved.'
        );
    }

    $globalRole = db()->prepare(
        'INSERT INTO user_roles
            (user_id, role_id, assigned_by)
         VALUES
            (:user_id, :role_id, :assigned_by)'
    );
    $globalRole->execute([
        'user_id' => $userId,
        'role_id' => $roleId,
        'assigned_by' => $userId,
    ]);

    $membership = db()->prepare(
        'INSERT INTO company_users
            (
                company_id,
                user_id,
                active,
                is_default,
                assigned_by
            )
         VALUES
            (
                :company_id,
                :user_id,
                1,
                1,
                :assigned_by
            )'
    );
    $membership->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
        'assigned_by' => $userId,
    ]);

    $companyRole = db()->prepare(
        'INSERT INTO company_user_roles
            (
                company_id,
                user_id,
                role_id,
                assigned_by
            )
         VALUES
            (
                :company_id,
                :user_id,
                :role_id,
                :assigned_by
            )'
    );
    $companyRole->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
        'role_id' => $roleId,
        'assigned_by' => $userId,
    ]);

    RepositoryFactory::auditLogs()->record(
        $userId,
        'CREATE_PLATFORM_ADMINISTRATOR',
        'administration',
        'users',
        (string) $userId,
        null,
        [
            'username' => $username,
            'email' => $email,
            'display_name' => $displayName,
            'must_change_password' => true,
        ],
        $companyId
    );

    db()->commit();
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    $fail(
        'Platform administrator creation failed: '
        . $exception->getMessage()
    );
}

fwrite(
    STDOUT,
    'Platform administrator created.'
    . ' A password change is required at first sign-in.'
    . PHP_EOL
);
