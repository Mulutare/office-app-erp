<?php

declare(strict_types=1);

require_once __DIR__
    . '/../app/helpers/bootstrap.php';

if (config('environment') !== 'development') {
    fwrite(
        STDERR,
        'Development administrator bootstrap was skipped'
        . ' outside development.'
        . PHP_EOL
    );

    exit(0);
}

$environmentValue = static function (
    string $name
): string {
    $value = getenv($name);

    return is_string($value)
        ? trim($value)
        : '';
};

$username = strtolower(
    $environmentValue('DEV_ADMIN_USERNAME')
);
$email = strtolower(
    $environmentValue('DEV_ADMIN_EMAIL')
);
$displayName = $environmentValue(
    'DEV_ADMIN_DISPLAY_NAME'
);
$password = $environmentValue(
    'DEV_ADMIN_PASSWORD'
);

if (
    !preg_match(
        '/^[a-z][a-z0-9._-]{2,49}$/',
        $username
    )
) {
    throw new RuntimeException(
        'DEV_ADMIN_USERNAME is invalid.'
    );
}

if (
    !filter_var($email, FILTER_VALIDATE_EMAIL)
    || strlen($email) > 190
) {
    throw new RuntimeException(
        'DEV_ADMIN_EMAIL is invalid.'
    );
}

if (
    mb_strlen($displayName) < 2
    || mb_strlen($displayName) > 150
) {
    throw new RuntimeException(
        'DEV_ADMIN_DISPLAY_NAME is invalid.'
    );
}

if (
    strlen($password) < 16
    || !preg_match('/[A-Z]/', $password)
    || !preg_match('/[a-z]/', $password)
    || !preg_match('/[0-9]/', $password)
    || !preg_match('/[^A-Za-z0-9]/', $password)
) {
    throw new RuntimeException(
        'DEV_ADMIN_PASSWORD must be at least 16'
        . ' characters and include upper, lower, number'
        . ' and special characters.'
    );
}

$existingStatement = db()->prepare(
    'SELECT
        user_id,
        is_platform_admin
     FROM users
     WHERE username = :username
       AND deleted_at IS NULL
     LIMIT 1'
);
$existingStatement->execute([
    'username' => $username,
]);
$existing = $existingStatement->fetch(
    PDO::FETCH_ASSOC
);

if (is_array($existing)) {
    if (empty($existing['is_platform_admin'])) {
        throw new RuntimeException(
            'The configured development administrator'
            . ' username belongs to a tenant account.'
        );
    }

    fwrite(
        STDOUT,
        'Development platform administrator already exists.'
        . PHP_EOL
    );

    exit(0);
}

$passwordHash = password_hash(
    $password,
    PASSWORD_DEFAULT
);

if (!is_string($passwordHash)) {
    throw new RuntimeException(
        'Unable to hash the development administrator password.'
    );
}

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
                TRUE,
                TRUE,
                TRUE,
                0
            )'
    );
    $insertUser->execute([
        'username' => $username,
        'email' => $email,
        'password_hash' => $passwordHash,
        'display_name' => $displayName,
    ]);

    $userId = (int) db()->lastInsertId();

    $contextStatement = db()->query(
        'SELECT
            companies.company_id,
            roles.role_id
         FROM companies
         CROSS JOIN roles
         WHERE companies.code = \'default\'
           AND companies.deleted_at IS NULL
           AND roles.code = \'system_administrator\'
           AND roles.active = TRUE
         LIMIT 1'
    );
    $context = $contextStatement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!is_array($context)) {
        throw new RuntimeException(
            'Vendor company or administrator role is missing.'
        );
    }

    $companyId = (int) $context['company_id'];
    $roleId = (int) $context['role_id'];

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
                TRUE,
                TRUE,
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

    db()->commit();
} catch (Throwable $exception) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    throw $exception;
}

fwrite(
    STDOUT,
    'Development platform administrator created.'
    . ' A password change is required at first login.'
    . PHP_EOL
);
