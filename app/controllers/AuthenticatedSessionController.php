<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditLog;
use App\Services\AuthenticatedSessionService;
use App\Services\AuthorizationService;
use App\Services\TenantContext;
use App\Services\UserDetailsService;
use Throwable;

final class AuthenticatedSessionController
{
    private AuthorizationService $authorization;
    private AuthenticatedSessionService $sessions;
    private UserDetailsService $users;
    private TenantContext $tenant;
    private AuditLog $audit;

    public function __construct()
    {
        $this->authorization = new AuthorizationService();
        $this->sessions = new AuthenticatedSessionService();
        $this->users = new UserDetailsService();
        $this->tenant = new TenantContext();
        $this->audit = new AuditLog();
    }

    public function terminateDashboardSession(string $id): void
    {
        $this->requireCsrf('/dashboard', 'dashboard_session_error');

        $sessionId = filter_var(
            $id,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!is_int($sessionId)) {
            $this->dashboardFailure('Invalid login session.');
        }

        $companyId = $this->tenant->companyId();
        $userId = (int) ($_SESSION['auth']['user_id'] ?? 0);
        $terminated = $this->sessions->terminateSession(
            $companyId,
            $userId,
            $sessionId
        );

        $this->record(
            'authenticated_session.terminated',
            $companyId,
            $userId,
            [
                'authenticated_user_session_id' => $sessionId,
                'terminated_count' => $terminated ? 1 : 0,
            ]
        );
        \flash(
            'dashboard_session_success',
            $terminated
                ? 'The login session was terminated.'
                : 'That login session was already inactive or could not be terminated.'
        );
        \redirect('/dashboard');
    }

    public function terminateDashboardOthers(): void
    {
        $this->requireCsrf('/dashboard', 'dashboard_session_error');
        $companyId = $this->tenant->companyId();
        $userId = (int) ($_SESSION['auth']['user_id'] ?? 0);

        try {
            $count = $this->sessions->terminateOtherSessions(
                $companyId,
                $userId
            );
        } catch (Throwable $exception) {
            $this->dashboardFailure(
                'Other sessions could not be terminated safely.'
            );
        }

        $this->record(
            'authenticated_sessions.others_terminated',
            $companyId,
            $userId,
            ['terminated_count' => $count]
        );
        \flash(
            'dashboard_session_success',
            $count === 1
                ? '1 other login session was terminated. Your current session remains active.'
                : $count . ' other login sessions were terminated. Your current session remains active.'
        );
        \redirect('/dashboard');
    }

    public function terminateAdminSession(): void
    {
        $this->authorization->requirePermission(
            'administration.users.manage'
        );
        $userId = $this->postInteger('user_id');
        $redirect = '/administration/users/view?id=' . $userId;
        $this->requireCsrf($redirect, 'user_session_error');
        $profile = $this->target($userId);
        $sessionId = $this->postInteger(
            'authenticated_user_session_id'
        );
        $companyId = $this->tenant->companyId();
        $terminated = $this->sessions->terminateSession(
            $companyId,
            $userId,
            $sessionId
        );

        $this->record(
            'authenticated_session.terminated',
            $companyId,
            $userId,
            [
                'authenticated_user_session_id' => $sessionId,
                'terminated_count' => $terminated ? 1 : 0,
            ]
        );
        \flash(
            'user_session_success',
            $terminated
                ? 'The login session for ' . (string) $profile['display_name'] . ' was terminated.'
                : 'That login session was already inactive or could not be terminated.'
        );
        \redirect($redirect);
    }

    public function terminateAdminAll(): void
    {
        $this->authorization->requirePermission(
            'administration.users.manage'
        );
        $userId = $this->postInteger('user_id');
        $redirect = '/administration/users/view?id=' . $userId;
        $this->requireCsrf($redirect, 'user_session_error');
        $profile = $this->target($userId);
        $companyId = $this->tenant->companyId();
        $isSelf = $userId === (int) (
            $_SESSION['auth']['user_id'] ?? 0
        );

        try {
            $count = $this->sessions->terminateAllSessions(
                $companyId,
                $userId
            );
        } catch (Throwable $exception) {
            \flash(
                'user_session_error',
                'Sessions could not be terminated safely.'
            );
            \redirect($redirect);
        }

        $this->record(
            $isSelf
                ? 'authenticated_sessions.others_terminated'
                : 'authenticated_sessions.all_terminated',
            $companyId,
            $userId,
            ['terminated_count' => $count]
        );
        \flash(
            'user_session_success',
            $isSelf
                ? $count . ' other login sessions were terminated. Your current session remains active.'
                : $count . ' active login sessions were terminated for ' . (string) $profile['display_name'] . '.'
        );
        \redirect($redirect);
    }

    /** @return array<string, mixed> */
    private function target(int $userId): array
    {
        $details = $this->users->details($userId);

        if ($details === null || !is_array($details['user'] ?? null)) {
            http_response_code(404);
            \view('errors.404', [
                'applicationName' => \config('name', 'OfficeApp ERP'),
            ]);
            exit;
        }

        return $details['user'];
    }

    private function requireCsrf(string $redirect, string $flashKey): void
    {
        if (\verifyCsrfToken(\postString('_token'))) {
            return;
        }

        http_response_code(419);
        \flash(
            $flashKey,
            'The form session expired. Please try again.'
        );
        \redirect($redirect);
    }

    private function postInteger(string $key): int
    {
        $value = filter_var(
            $_POST[$key] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return is_int($value) ? $value : 0;
    }

    private function dashboardFailure(string $message): never
    {
        \flash('dashboard_session_error', $message);
        \redirect('/dashboard');
    }

    /** @param array<string, int> $metadata */
    private function record(
        string $action,
        int $companyId,
        int $targetUserId,
        array $metadata
    ): void {
        $this->audit->record(
            (int) ($_SESSION['auth']['user_id'] ?? 0),
            $action,
            'authentication',
            'users',
            (string) $targetUserId,
            null,
            ['target_user_id' => $targetUserId] + $metadata,
            $companyId
        );
    }
}
