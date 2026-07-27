<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CompanyMembership;
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
    private CompanyMembership $memberships;
    private CompanyModuleService $companyModules;

    public function __construct()
    {
        $this->users = new User();
        $this->loginAttempts = new LoginAttempt();
        $this->auditLogs = new AuditLog();
        $this->memberships =
            new CompanyMembership();
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
        $isPlatformAdmin = !empty(
            $user['is_platform_admin']
        );
        $companyId = $this
            ->authenticationCompanyId(
                $userId,
                $isPlatformAdmin
            );

        if (!(bool) $user['active']) {
            $this->loginAttempts->record(
                $login,
                $userId,
                false,
                'account_inactive',
                \requestIp(),
                \requestUserAgent(),
                $companyId
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
                \requestUserAgent(),
                $companyId
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
                (int) $user['failed_login_count'],
                $companyId
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
        $isPlatformAdmin = !empty(
            $user['is_platform_admin']
        );
        $_SESSION['auth']['is_platform_admin'] =
            $isPlatformAdmin;
        $companies = $this->memberships
            ->activeForUser(
                $userId,
                $isPlatformAdmin
            );
        $currentCompanyId = $_SESSION['auth'][
            'company'
        ]['company_id'] ?? null;
        $currentMembership = is_int(
            $currentCompanyId
        )
            ? $this->memberships
                ->activeMembership(
                    $userId,
                    $currentCompanyId,
                    $isPlatformAdmin
                )
            : null;

        if ($currentMembership === null) {
            unset($_SESSION['auth']);

            return false;
        }

        $_SESSION['auth']['roles'] =
            $this->memberships->roleCodes(
                $userId,
                $currentCompanyId
            );
        $_SESSION['auth']['permissions'] =
            $this->memberships->permissionCodes(
                $userId,
                $currentCompanyId
            );
        $_SESSION['auth']['must_change_password'] =
            (bool) $user['must_change_password'];
        $_SESSION['auth']['company'] =
            $currentMembership;
        $_SESSION['auth']['companies'] =
            $companies;
        $_SESSION['auth']['modules'] =
            $isPlatformAdmin
                ? []
                : $this->companyModules
                    ->enabledNavigationModules(
                        $currentCompanyId
                    );

        return true;
    }

    public function switchCompany(int $companyId): bool
    {
        $userId = $this->userId();

        if ($userId === null) {
            return false;
        }

        $membership = $this->memberships
            ->activeMembership(
                $userId,
                $companyId,
                $this->isPlatformAdministrator()
            );

        if ($membership === null) {
            return false;
        }

        $previousCompanyId = (int) (
            $_SESSION['auth']['company'][
                'company_id'
            ] ?? 0
        );

        $this->applyCompanyContext(
            $userId,
            $membership,
            $this->memberships
                ->activeForUser(
                    $userId,
                    $this->isPlatformAdministrator()
                )
        );

        session_regenerate_id(true);

        $this->auditLogs->record(
            $userId,
            'SWITCH_COMPANY',
            'authentication',
            'companies',
            (string) $companyId,
            [
                'company_id' =>
                    $previousCompanyId,
            ],
            [
                'company_id' => $companyId,
                'company_code' =>
                    $membership['code'],
            ]
        );

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

public function isPlatformAdministrator(): bool
{
    return !empty(
        $_SESSION['auth']['is_platform_admin']
    );
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
    $isPlatformAdmin = !empty(
        $user['is_platform_admin']
    );
    $companies = $this->memberships
        ->activeForUser(
            $userId,
            $isPlatformAdmin
        );

    if ($companies === []) {
        $this->loginAttempts->record(
            (string) $user['username'],
            $userId,
            false,
            'company_access_unavailable',
            \requestIp(),
            \requestUserAgent(),
            null
        );

        return $this->failure(
            'Your account is not assigned to an active company workspace.'
        );
    }

    $company = $companies[0];
    $companyId = (int) $company['company_id'];

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
            \requestUserAgent(),
            $companyId
        );

        $roles = $this->memberships
            ->roleCodes(
                $userId,
                $companyId
            );

        $permissions = $this->memberships
            ->permissionCodes(
                $userId,
                $companyId
            );
        $modules = $isPlatformAdmin
            ? []
            : $this->companyModules
                ->enabledNavigationModules(
                    $companyId
                );

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
            ],
            $companyId
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
        'is_platform_admin' =>
            $isPlatformAdmin,
        'roles' => $roles,
        'permissions' => $permissions,
        'company' => $company,
        'companies' => $companies,
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
        int $currentFailureCount,
        ?int $companyId
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
            \requestUserAgent(),
            $companyId
        );
    }

    private function authenticationCompanyId(
        int $userId,
        bool $platformOnly = false
    ): ?int {
        $companies = $this->memberships
            ->activeForUser(
                $userId,
                $platformOnly
            );

        if ($companies === []) {
            return null;
        }

        $companyId = $companies[0]['company_id']
            ?? null;

        return is_int($companyId)
            && $companyId > 0
                ? $companyId
                : null;
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

    /**
     * @param array<string, mixed> $company
     * @param list<array<string, mixed>> $companies
     */
    private function applyCompanyContext(
        int $userId,
        array $company,
        array $companies
    ): void {
        $companyId = (int) $company['company_id'];

        $_SESSION['auth']['company'] = $company;
        $_SESSION['auth']['companies'] = $companies;
        $_SESSION['auth']['roles'] =
            $this->memberships->roleCodes(
                $userId,
                $companyId
            );
        $_SESSION['auth']['permissions'] =
            $this->memberships->permissionCodes(
                $userId,
                $companyId
            );
        $_SESSION['auth']['modules'] =
            $this->isPlatformAdministrator()
                ? []
                : $this->companyModules
                    ->enabledNavigationModules(
                        $companyId
                    );
    }
}
