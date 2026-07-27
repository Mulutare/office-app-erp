<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\UserActivity;

final class UserActivityService
{
    private const PAGE_SIZE = 20;

    private User $users;
    private UserActivity $activity;
    private AuditChangePresenter $changes;
    private TenantContext $tenant;

    public function __construct()
    {
        $this->users = new User();
        $this->activity = new UserActivity();
        $this->changes =
            new AuditChangePresenter();
        $this->tenant = new TenantContext();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function listing(
        int $userId,
        string $type,
        int $page
    ): ?array {
        if ($userId < 1) {
            return null;
        }

        $user = $this->users->findByIdInCompany(
            $userId,
            $this->tenant->companyId()
        );

        if ($user === null) {
            return null;
        }

        $allowedTypes = [
            'all',
            'authentication',
            'administration',
        ];

        if (!in_array($type, $allowedTypes, true)) {
            $type = 'all';
        }

        $page = max(1, $page);
        $companyId = $this->tenant->companyId();
        $total = $this->activity->countForUser(
            $companyId,
            $userId,
            $type
        );
        $lastPage = max(
            1,
            (int) ceil($total / self::PAGE_SIZE)
        );
        $page = min($page, $lastPage);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $events = $this->activity->pageForUser(
            $companyId,
            $userId,
            $type,
            self::PAGE_SIZE,
            $offset
        );

        foreach ($events as &$event) {
            $event = $this->presentEvent(
                $event,
                $userId
            );
        }

        unset($event);

        return [
            'user' => $user,
            'events' => $events,
            'filters' => [
                'type' => $type,
            ],
            'pagination' => [
                'page' => $page,
                'lastPage' => $lastPage,
                'pageSize' => self::PAGE_SIZE,
                'total' => $total,
                'from' => $total === 0
                    ? 0
                    : $offset + 1,
                'to' => min(
                    $offset + self::PAGE_SIZE,
                    $total
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array<string, mixed>
     */
    private function presentEvent(
        array $event,
        int $userId
    ): array {
        $action = (string) (
            $event['action'] ?? ''
        );
        $actor = trim((string) (
            $event['actor_name']
            ?? $event['actor_username']
            ?? ''
        ));

        if ($actor === '') {
            $actor = 'System';
        }

        $labels = [
            'CREATE' => 'Account created',
            'UPDATE' => 'Account updated',
            'PASSWORD_RESET' =>
                'Password reset by administrator',
            'PASSWORD_CHANGE' => 'Password changed',
            'ENABLE' => 'Account activated',
            'DISABLE' => 'Account deactivated',
            'UNLOCK' => 'Account unlocked',
            'LOGOUT' => 'Signed out',
            'UPDATE_PERMISSIONS' =>
                'Role permissions updated',
            'LOGIN_SUCCESS' => 'Successful sign-in',
            'LOGIN_FAILED' => 'Failed sign-in',
        ];

        $event['label'] =
            $labels[$action]
            ?? ucwords(strtolower(
                str_replace('_', ' ', $action)
            ));
        $event['actor_label'] = $actor;
        $event['description'] =
            $this->descriptionFor(
                $event,
                $actor,
                $userId
            );
        $event['tone'] = $this->toneFor(
            $action
        );
        $event['changes'] = $this->changes->changes(
            $event['old_values'] ?? null,
            $event['new_values'] ?? null
        );

        unset(
            $event['old_values'],
            $event['new_values']
        );

        return $event;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function descriptionFor(
        array $event,
        string $actor,
        int $userId
    ): string {
        if (
            ($event['source'] ?? '')
            === 'login_attempt'
        ) {
            if (!empty($event['successful'])) {
                return
                    'Authentication completed successfully.';
            }

            $reasons = [
                'invalid_password' =>
                    'The submitted password was incorrect.',
                'invalid_password_account_locked' =>
                    'Repeated failures triggered a temporary account lock.',
                'account_locked' =>
                    'Sign-in was blocked because the account was locked.',
                'account_inactive' =>
                    'Sign-in was blocked because the account was inactive.',
            ];
            $reason = (string) (
                $event['failure_reason'] ?? ''
            );

            return $reasons[$reason]
                ?? 'Authentication was unsuccessful.';
        }

        $targetIsUser = (
            $event['target_table'] ?? ''
        ) === 'users'
            && (int) (
                $event['target_id'] ?? 0
            ) === $userId;

        if ($targetIsUser) {
            return $actor
                . ' performed this action on the account.';
        }

        $target = trim((string) (
            $event['target_table'] ?? ''
        ));

        return $target === ''
            ? $actor . ' performed this action.'
            : $actor . ' performed this action on '
                . $target . '.';
    }

    private function toneFor(string $action): string
    {
        if ($action === 'LOGIN_SUCCESS'
            || $action === 'ENABLE'
            || $action === 'UNLOCK'
        ) {
            return 'success';
        }

        if ($action === 'LOGIN_FAILED'
            || $action === 'DISABLE'
        ) {
            return 'danger';
        }

        if ($action === 'PASSWORD_RESET'
            || $action === 'PASSWORD_CHANGE'
        ) {
            return 'warning';
        }

        return 'information';
    }

}
