<?php

declare(strict_types=1);

namespace App\Controllers;
use App\Services\UserCreationService;
use App\Services\AuthorizationService;
use App\Services\UserAdministrationService;
use App\Services\UserDetailsService;
use App\Services\UserUpdateService;
use App\Services\UserPasswordResetService;
use App\Services\UserAccountStatusService;
use App\Services\UserAccountUnlockService;

final class UserAdministrationController
{
    private UserCreationService $creation;
    private AuthorizationService $authorization;
    private UserAdministrationService $users;
    private UserDetailsService $details;
    private UserUpdateService $updates;
    private UserPasswordResetService $passwordResets;
    private UserAccountStatusService $accountStatus;
    private UserAccountUnlockService $accountUnlocks;

    public function __construct()
    {
        $this->creation =
    new UserCreationService();
        $this->authorization =
            new AuthorizationService();

        $this->users =
            new UserAdministrationService();

        $this->details =
            new UserDetailsService();

        $this->updates =
            new UserUpdateService();

        $this->passwordResets =
            new UserPasswordResetService();

        $this->accountStatus =
            new UserAccountStatusService();

        $this->accountUnlocks =
            new UserAccountUnlockService();
    }

    public function show(): void
    {
        $this->authorization
            ->requirePermission(
                'administration.users.manage'
            );

        $userId = $this->queryInteger('id', 0);
        $details = $this->details->details($userId);

        if ($details === null) {
            $this->notFound();
        }

        $profile = $details['user'];
        $currentUserId = (int) (
            $_SESSION['auth']['user_id'] ?? 0
        );
        $isSelf = (int) (
            $profile['user_id'] ?? 0
        ) === $currentUserId;
        $isProtectedOwner =
            !empty($profile['is_primary_owner'])
            && !$isSelf;

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => (string) (
                $profile['display_name']
                ?? 'User Details'
            ),
            'pageDescription' =>
                'Account, access and security information.',
            'contentView' =>
                'administration.users.show',
            'user' => $_SESSION['auth'],
            'profile' => $profile,
            'roles' => $details['roles'],
            'permissions' =>
                $details['permissions'],
            'loginAttempts' =>
                $details['loginAttempts'],
            'auditActivity' =>
                $details['auditActivity'],
            'successMessage' => \getFlash(
                'user_update_success'
            ),
            'canEdit' => in_array(
                'administration.roles.manage',
                $_SESSION['auth']['permissions'] ?? [],
                true
            ) && !$isProtectedOwner,
            'canResetPassword' =>
                !$isSelf && !$isProtectedOwner,
            'resetCredentials' => \getFlash(
                'reset_user_credentials'
            ),
            'canChangeStatus' =>
                !$isSelf && !$isProtectedOwner,
            'canUnlock' => (
                !empty($profile['is_locked'])
                || (int) (
                    $profile['failed_login_count'] ?? 0
                ) > 0
            ) && !$isSelf,
            'canViewActivity' => in_array(
                'audit.logs.view',
                $_SESSION['auth']['permissions'] ?? [],
                true
            ),
        ]);
    }

    public function showUnlockAccount(): void
    {
        $this->authorization->requirePermission(
            'administration.users.manage'
        );

        $userId = $this->queryInteger('id', 0);
        $profile = $this->accountUnlocks
            ->target($userId);

        if ($profile === null) {
            $this->notFound();
        }

        if ($userId === (int) (
            $_SESSION['auth']['user_id'] ?? 0
        )) {
            \flash(
                'user_update_success',
                'You cannot unlock your own account from user administration.'
            );

            \redirect(
                '/administration/users/view?id='
                . $userId
            );
        }

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Unlock User Account',
            'pageDescription' =>
                'Clear the temporary lock and failed-login counter.',
            'contentView' =>
                'administration.users.unlock',
            'user' => $_SESSION['auth'],
            'profile' => $profile,
            'errors' => \getFlash(
                'account_unlock_errors',
                []
            ),
        ]);
    }

    public function unlockAccount(): void
    {
        $this->authorization->requirePermission(
            'administration.users.manage'
        );

        $userId = $this->postInteger('user_id');

        if (!\verifyCsrfToken(
            \postString('_token')
        )) {
            \flash('account_unlock_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);

            \redirect(
                '/administration/users/unlock?id='
                . $userId
            );
        }

        $result = $this->accountUnlocks->unlock(
            $userId,
            (int) (
                $_SESSION['auth']['user_id'] ?? 0
            )
        );

        if (!empty($result['notFound'])) {
            $this->notFound();
        }

        if (!$result['successful']) {
            \flash(
                'account_unlock_errors',
                $result['errors']
            );

            \redirect(
                '/administration/users/unlock?id='
                . $userId
            );
        }

        \flash(
            'user_update_success',
            !empty($result['changed'])
                ? 'User account unlocked successfully.'
                : 'User account is not locked.'
        );

        \redirect(
            '/administration/users/view?id='
            . $userId
        );
    }

    public function showAccountStatus(): void
    {
        $this->authorization->requirePermission(
            'administration.users.manage'
        );

        $userId = $this->queryInteger('id', 0);
        $profile = $this->accountStatus
            ->target($userId);

        if ($profile === null) {
            $this->notFound();
        }

        if ($userId === (int) (
            $_SESSION['auth']['user_id'] ?? 0
        )) {
            \flash(
                'user_update_success',
                'You cannot change the status of your own account.'
            );

            \redirect(
                '/administration/users/view?id='
                . $userId
            );
        }

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => !empty($profile['active'])
                ? 'Deactivate User'
                : 'Activate User',
            'pageDescription' =>
                'Confirm the requested account status change.',
            'contentView' =>
                'administration.users.account-status',
            'user' => $_SESSION['auth'],
            'profile' => $profile,
            'errors' => \getFlash(
                'account_status_errors',
                []
            ),
        ]);
    }

    public function changeAccountStatus(): void
    {
        $this->authorization->requirePermission(
            'administration.users.manage'
        );

        $userId = $this->postInteger('user_id');
        $active = $this->postBoolean('active');

        if ($active === null) {
            \flash('account_status_errors', [
                'form' =>
                    'The requested account status is invalid.',
            ]);

            \redirect(
                '/administration/users/account-status?id='
                . $userId
            );
        }

        if (!\verifyCsrfToken(
            \postString('_token')
        )) {
            \flash('account_status_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);

            \redirect(
                '/administration/users/account-status?id='
                . $userId
            );
        }

        $result = $this->accountStatus->change(
            $userId,
            $active,
            (int) (
                $_SESSION['auth']['user_id'] ?? 0
            )
        );

        if (!empty($result['notFound'])) {
            $this->notFound();
        }

        if (!$result['successful']) {
            \flash(
                'account_status_errors',
                $result['errors']
            );

            \redirect(
                '/administration/users/account-status?id='
                . $userId
            );
        }

        $message = $active
            ? 'User account activated successfully.'
            : 'User account deactivated successfully.';

        if (empty($result['changed'])) {
            $message = $active
                ? 'User account is already active.'
                : 'User account is already inactive.';
        }

        \flash('user_update_success', $message);

        \redirect(
            '/administration/users/view?id='
            . $userId
        );
    }

    public function showResetPassword(): void
    {
        $this->authorization->requirePermission(
            'administration.users.manage'
        );

        $userId = $this->queryInteger('id', 0);
        $profile = $this->passwordResets
            ->target($userId);

        if ($profile === null) {
            $this->notFound();
        }

        if ($userId === (int) (
            $_SESSION['auth']['user_id'] ?? 0
        )) {
            \flash(
                'user_update_success',
                'Use the Change Password page to change your own password.'
            );

            \redirect(
                '/administration/users/view?id='
                . $userId
            );
        }

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Reset User Password',
            'pageDescription' =>
                'Issue a one-time temporary password for this account.',
            'contentView' =>
                'administration.users.reset-password',
            'user' => $_SESSION['auth'],
            'profile' => $profile,
            'errors' => \getFlash(
                'password_reset_errors',
                []
            ),
        ]);
    }

    public function resetPassword(): void
    {
        $this->authorization->requirePermission(
            'administration.users.manage'
        );

        $userId = $this->postInteger('user_id');

        if (!\verifyCsrfToken(
            \postString('_token')
        )) {
            \flash('password_reset_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);

            \redirect(
                '/administration/users/reset-password?id='
                . $userId
            );
        }

        $result = $this->passwordResets->reset(
            $userId,
            (int) (
                $_SESSION['auth']['user_id'] ?? 0
            )
        );

        if (!empty($result['notFound'])) {
            $this->notFound();
        }

        if (!$result['successful']) {
            \flash(
                'password_reset_errors',
                $result['errors']
            );

            \redirect(
                '/administration/users/reset-password?id='
                . $userId
            );
        }

        \flash('reset_user_credentials', [
            'username' => $result['username'],
            'temporary_password' =>
                $result['temporaryPassword'],
        ]);

        \redirect(
            '/administration/users/view?id='
            . $userId
        );
    }

    public function edit(): void
    {
        $this->requireUserAndRoleManagement();

        $userId = $this->queryInteger('id', 0);
        $formData = $this->updates
            ->formData($userId);

        if ($formData === null) {
            $this->notFound();
        }

        $old = \getFlash('user_edit_old', []);

        if (is_array($old) && $old !== []) {
            $formData['profile'] = array_merge(
                $formData['profile'],
                $old
            );
        }

        $isSelf = $userId === (int) (
            $_SESSION['auth']['user_id'] ?? 0
        );

        if ($isSelf) {
            $current = $this->updates
                ->formData($userId);

            if ($current !== null) {
                $formData['profile']['role_ids'] =
                    $current['profile']['role_ids'];
                $formData['profile']['active'] =
                    $current['profile']['active'];
            }
        }

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'Edit User',
            'pageDescription' =>
                'Update account identity, status and assigned roles.',
            'contentView' =>
                'administration.users.edit',
            'user' => $_SESSION['auth'],
            'profile' => $formData['profile'],
            'roles' => $formData['roles'],
            'isSelf' => $isSelf,
            'errors' => \getFlash(
                'user_edit_errors',
                []
            ),
        ]);
    }

    public function update(): void
    {
        $this->requireUserAndRoleManagement();

        $userId = $this->postInteger('user_id');

        if (!\verifyCsrfToken(
            \postString('_token')
        )) {
            \flash('user_edit_errors', [
                'form' =>
                    'The form session expired. Please try again.',
            ]);

            \redirect(
                '/administration/users/edit?id='
                . $userId
            );
        }

        $input = [
            'username' => \postString('username'),
            'email' => \postString('email'),
            'display_name' =>
                \postString('display_name'),
            'active' => isset($_POST['active']),
            'role_ids' =>
                $_POST['role_ids'] ?? [],
        ];

        $actorId = (int) (
            $_SESSION['auth']['user_id'] ?? 0
        );
        $result = $this->updates->update(
            $userId,
            $input,
            $actorId
        );

        if (!empty($result['notFound'])) {
            $this->notFound();
        }

        if (!$result['successful']) {
            \flash(
                'user_edit_errors',
                $result['errors']
            );
            \flash('user_edit_old', array_merge(
                $input,
                ['user_id' => $userId]
            ));

            \redirect(
                '/administration/users/edit?id='
                . $userId
            );
        }

        if ($userId === $actorId) {
            $_SESSION['auth']['username'] =
                $result['profile']['username'];
            $_SESSION['auth']['display_name'] =
                $result['profile']['display_name'];
        }

        \flash(
            'user_update_success',
            'User account updated successfully.'
        );

        \redirect(
            '/administration/users/view?id='
            . $userId
        );
    }

    public function index(): void
    {
        $this->authorization
            ->requirePermission(
                'administration.users.manage'
            );

        $listing = $this->users->listing(
            $this->queryString('search'),
            $this->queryString(
                'status',
                'all'
            ),
            $this->queryString(
                'sort',
                'created_at'
            ),
            $this->queryString(
                'direction',
                'desc'
            ),
            $this->queryInteger(
                'page',
                1
            )
        );

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' => 'User Management',
            'pageDescription' =>
                'Manage ERP accounts, access roles and account security.',
            'contentView' =>
                'administration.users.index',
            'user' => $_SESSION['auth'],
            'users' => $listing['users'],
            'filters' => $listing['filters'],
            'pagination' =>
                $listing['pagination'],
                'createdCredentials' => \getFlash(
    'created_user_credentials'
),
        ]);
    }

    private function queryString(
        string $key,
        string $default = ''
    ): string {
        $value = $_GET[$key] ?? $default;

        return is_string($value)
            ? trim($value)
            : $default;
    }

    private function queryInteger(
        string $key,
        int $default
    ): int {
        $value = $_GET[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (
            is_string($value)
            && ctype_digit($value)
        ) {
            return (int) $value;
        }

        return $default;
    }

    private function postInteger(string $key): int
    {
        $value = $_POST[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value)
            && ctype_digit($value)
                ? (int) $value
                : 0;
    }

    private function postBoolean(string $key): ?bool
    {
        $value = $_POST[$key] ?? null;

        if ($value === '1' || $value === 1) {
            return true;
        }

        if ($value === '0' || $value === 0) {
            return false;
        }

        return null;
    }

    private function requireUserAndRoleManagement(): void
    {
        $this->authorization->requirePermission(
            'administration.users.manage'
        );
        $this->authorization->requirePermission(
            'administration.roles.manage'
        );
    }

    private function notFound(): void
    {
        http_response_code(404);

        \view('errors.404', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
        ]);

        exit;
    }
    public function create(): void
{
    $this->authorization
        ->requirePermission(
            'administration.users.manage'
        );

    $this->authorization
        ->requirePermission(
            'administration.roles.manage'
        );

    \view('layouts.app', [
        'applicationName' => \config(
            'name',
            'OfficeApp ERP'
        ),
        'environment' => \config(
            'environment',
            'unknown'
        ),
        'pageTitle' => 'Create User',
        'pageDescription' =>
            'Create a secure ERP account and assign access roles.',
        'contentView' =>
            'administration.users.create',
        'user' => $_SESSION['auth'],
        'roles' => $this->creation->roles(),
        'errors' => \getFlash(
            'user_create_errors',
            []
        ),
        'old' => \getFlash(
            'user_create_old',
            []
        ),
    ]);
}

public function store(): void
{
    $this->authorization
        ->requirePermission(
            'administration.users.manage'
        );

    $this->authorization
        ->requirePermission(
            'administration.roles.manage'
        );

    if (
        !\verifyCsrfToken(
            \postString('_token')
        )
    ) {
        \flash(
            'user_create_errors',
            [
                'form' =>
                    'The form session expired. Please try again.',
            ]
        );

        \redirect(
            '/administration/users/create'
        );
    }

    $input = [
        'username' =>
            \postString('username'),
        'email' =>
            \postString('email'),
        'display_name' =>
            \postString('display_name'),
        'active' =>
            isset($_POST['active']),
        'role_ids' =>
            $_POST['role_ids'] ?? [],
    ];

    $result = $this->creation->create(
        $input,
        (int) $_SESSION['auth']['user_id']
    );

    if (!$result['successful']) {
        \flash(
            'user_create_errors',
            $result['errors']
        );

        \flash(
            'user_create_old',
            $input
        );

        \redirect(
            '/administration/users/create'
        );
    }

    \flash(
        'created_user_credentials',
        [
            'username' => $input['username'],
            'temporary_password' =>
                $result['temporaryPassword'],
        ]
    );

    \redirect('/administration/users');
}
}
