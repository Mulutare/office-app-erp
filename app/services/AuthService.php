<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\LoginAttempt;
use App\Models\User;
use DateTimeImmutable;
use Throwable;

final class AuthService
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;

    private User $users;
    private LoginAttempt $loginAttempts;
    private AuditLog $auditLogs;
    private CompanyModuleService $companyModules;

    public function __construct()
    {
        $this->users = new User();
        $this->loginAttempts = new LoginAttempt();
        $this->auditLogs = new AuditLog();
        $this->companyModules =
            new CompanyModuleService();
    }

    /**
     * @return array{
     *     successful: bool,
     *     message: string
     * }
     */
    public function attempt(
        string $login,
        string $password
    ): array {
        $login = trim($login);

        if ($login === '' || $password === '') {
            return $this->failure(
                'Username/email and password are required.'
            );
        }

        $user = $this->users
            ->findForAuthentication($login);

        if ($user === null) {
            $this->loginAttempts->record(
                $login,
                null,
                false,
                'user_not_found',
                \requestIp(),
                \requestUserAgent()
            );

            return $this->failure(
                'The username/email or password is incorrect.'
            );
        }

        $userId = (int) $user['user_id'];

        if (!(bool) $user['active']) {
            $this->loginAttempts->record(
                $login,
                $userId,
                false,
                'account_inactive',
                \requestIp(),
                \requestUserAgent()
            );

            return $this->failure(
                'The username/email or password is incorrect.'
            );
        }

        if ($this->isLocked($user['locked_until'] ?? null)) {
            $this->loginAttempts->record(
                $login,
                $userId,
                false,
                'account_locked',
                \requestIp(),
                \requestUserAgent()
            );

            return $this->failure(
                'The account is temporarily locked. Try again later.'
            );
        }

        $passwordHash = (string) (
            $user['password_hash'] ?? ''
        );

        if (
            $passwordHash === ''
            || !password_verify($password, $passwordHash)
        ) {
            $this->recordFailedPassword(
                $userId,
                $login,
                (int) $user['failed_login_count']
            );

            return $this->failure(
                'The username/email or password is incorrect.'
            );
        }

        return $this->completeLogin($user);
    }

    public function logout(): void
    {
        $userId = $this->userId();

        if ($userId !== null) {
            $this->auditLogs->record(
                $userId,
                'LOGOUT',
                'authentication',
                'users',
                (string) $userId
            );
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $parameters['path'],
                $parameters['domain'],
                $parameters['secure'],
                $parameters['httponly']
            );
        }

        session_destroy();
    }

    public function check(): bool
    {
        $userId = $_SESSION['auth']['user_id']
            ?? null;

        if (!is_int($userId)) {
            return false;
        }

        $user = $this->users->findById($userId);

        if (
            $user === null
            || empty($user['active'])
        ) {
            unset($_SESSION['auth']);

            return false;
        }

        $_SESSION['auth']['username'] =
            (string) $user['username'];
        $_SESSION['auth']['display_name'] =
            (string) $user['display_name'];
        $_SESSION['auth']['roles'] =
            $this->users->roleCodes($userId);
        $_SESSION['auth']['permissions'] =
            $this->users->permissionCodes($userId);
        $_SESSION['auth']['must_change_password'] =
            (bool) $user['must_change_password'];
        $_SESSION['auth']['company'] =
            $this->companyModules->company();
        $_SESSION['auth']['modules'] =
            $this->companyModules
                ->enabledNavigationModules();

        return true;
    }

    public function userId(): ?int
    {
        $userId = $_SESSION['auth']['user_id']
            ?? null;

        return is_int($userId)
            ? $userId
            : null;
    }
/**
 * Check whether the signed-in user has a specific role.
 */
public function hasRole(string $roleCode): bool
{
    $roles = $_SESSION['auth']['roles'] ?? [];

    if (!is_array($roles)) {
        return false;
    }

    return in_array(
        $roleCode,
        $roles,
        true
    );
}

/**
 * Check whether the signed-in user has a permission.
 */
public function can(string $permissionCode): bool
{
    $permissions =
        $_SESSION['auth']['permissions'] ?? [];

    if (!is_array($permissions)) {
        return false;
    }

    return in_array(
        $permissionCode,
        $permissions,
        true
    );
}

/**
 * Check whether the signed-in user has at least one
 * permission from the supplied list.
 *
 * @param list<string> $permissionCodes
 */
public function canAny(array $permissionCodes): bool
{
    foreach ($permissionCodes as $permissionCode) {
        if (
            is_string($permissionCode)
            && $this->can($permissionCode)
        ) {
            return true;
        }
    }

    return false;
}
    /**
     * @param array<string, mixed> $user
     *
     * @return array{
     *     successful: bool,
     *     message: string
     * }
     */
    /**
 * @return array{
 *     successful: bool,
 *     message: string
 * }
 */
public function changePassword(
    string $currentPassword,
    string $newPassword,
    string $confirmation
): array {
    $userId = $this->userId();

    if ($userId === null) {
        return $this->failure(
            'You must be signed in.'
        );
    }

    if (
        $currentPassword === ''
        || $newPassword === ''
        || $confirmation === ''
    ) {
        return $this->failure(
            'All password fields are required.'
        );
    }

    if ($newPassword !== $confirmation) {
        return $this->failure(
            'The new password confirmation does not match.'
        );
    }

    if (strlen($newPassword) < 12) {
        return $this->failure(
            'The new password must contain at least 12 characters.'
        );
    }

    if (!preg_match('/[A-Z]/', $newPassword)) {
        return $this->failure(
            'The new password must contain an uppercase letter.'
        );
    }

    if (!preg_match('/[a-z]/', $newPassword)) {
        return $this->failure(
            'The new password must contain a lowercase letter.'
        );
    }

    if (!preg_match('/[0-9]/', $newPassword)) {
        return $this->failure(
            'The new password must contain a number.'
        );
    }

    if (!preg_match('/[^A-Za-z0-9]/', $newPassword)) {
        return $this->failure(
            'The new password must contain a special character.'
        );
    }

    if ($currentPassword === $newPassword) {
        return $this->failure(
            'The new password must be different from the current password.'
        );
    }

    $currentHash = $this->users
        ->passwordHashById($userId);

    if (
        $currentHash === null
        || !password_verify(
            $currentPassword,
            $currentHash
        )
    ) {
        return $this->failure(
            'The current password is incorrect.'
        );
    }

    $newHash = password_hash(
        $newPassword,
        PASSWORD_DEFAULT
    );

    if (!is_string($newHash)) {
        throw new \RuntimeException(
            'Password hashing failed.'
        );
    }

    try {
        \db()->beginTransaction();

        $this->users->updatePassword(
            $userId,
            $newHash
        );

        $this->auditLogs->record(
            $userId,
            'PASSWORD_CHANGE',
            'authentication',
            'users',
            (string) $userId
        );

        \db()->commit();
    } catch (\Throwable $exception) {
        if (\db()->inTransaction()) {
            \db()->rollBack();
        }

        throw $exception;
    }

    $_SESSION['auth']['must_change_password'] = false;

    session_regenerate_id(true);

    return [
        'successful' => true,
        'message' => 'Password changed successfully.',
    ];
}
    /**
 * Complete a successful authentication.
 *
 * @param array<string, mixed> $user
 *
 * @return array{
 *     successful: bool,
 *     message: string
 * }
 */
private function completeLogin(array $user): array
{
    $userId = (int) $user['user_id'];

    try {
        \db()->beginTransaction();

        $this->users->recordSuccessfulLogin(
            $userId
        );

        $this->loginAttempts->record(
            (string) $user['username'],
            $userId,
            true,
            null,
            \requestIp(),
            \requestUserAgent()
        );

        $roles = $this->users
            ->roleCodes($userId);

        $permissions = $this->users
            ->permissionCodes($userId);
        $company = $this->companyModules
            ->company();
        $modules = $this->companyModules
            ->enabledNavigationModules();

        $this->auditLogs->record(
            $userId,
            'LOGIN',
            'authentication',
            'users',
            (string) $userId,
            null,
            [
                'username' => $user['username'],
                'roles' => $roles,
                'permissions' => $permissions,
                'company_code' => $company['code'],
                'modules' => array_values(array_map(
                    static fn (array $module): string =>
                        (string) $module['code'],
                    $modules
                )),
            ]
        );

        \db()->commit();
    } catch (Throwable $exception) {
        if (\db()->inTransaction()) {
            \db()->rollBack();
        }

        throw $exception;
    }

    session_regenerate_id(true);

    $_SESSION['auth'] = [
        'user_id' => $userId,
        'username' => (string) $user['username'],
        'display_name' =>
            (string) $user['display_name'],
        'roles' => $roles,
        'permissions' => $permissions,
        'company' => $company,
        'modules' => $modules,
        'must_change_password' =>
            (bool) $user['must_change_password'],
        'authenticated_at' => time(),
    ];

    return [
        'successful' => true,
        'message' => 'Authentication successful.',
    ];
}
    private function recordFailedPassword(
        int $userId,
        string $login,
        int $currentFailureCount
    ): void {
        $newFailureCount =
            $currentFailureCount + 1;

        $this->users
            ->incrementFailedLoginCount($userId);

        $failureReason = 'invalid_password';

        if (
            $newFailureCount
            >= self::MAX_FAILED_ATTEMPTS
        ) {
            $lockedUntil = new DateTimeImmutable(
                '+' . self::LOCK_MINUTES . ' minutes'
            );

            $this->users->lockUntil(
                $userId,
                $lockedUntil->format(
                    'Y-m-d H:i:s'
                )
            );

            $failureReason =
                'invalid_password_account_locked';
        }

        $this->loginAttempts->record(
            $login,
            $userId,
            false,
            $failureReason,
            \requestIp(),
            \requestUserAgent()
        );
    }

    private function isLocked(
        mixed $lockedUntil
    ): bool {
        if (
            !is_string($lockedUntil)
            || trim($lockedUntil) === ''
        ) {
            return false;
        }

        try {
            return new DateTimeImmutable(
                $lockedUntil
            ) > new DateTimeImmutable();
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * @return array{
     *     successful: false,
     *     message: string
     * }
     */
    private function failure(string $message): array
    {
        return [
            'successful' => false,
            'message' => $message,
        ];
    }
}
