<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

final class UserAdministrationService
{
    private const PAGE_SIZE = 20;

    private User $users;
    private TenantContext $tenant;

    public function __construct()
    {
        $this->users = new User();
        $this->tenant = new TenantContext();
    }

    /**
     * @return array<string, mixed>
     */
    public function listing(
        string $search,
        string $status,
        string $sort,
        string $direction,
        int $page
    ): array {
        $search = mb_substr(
            trim($search),
            0,
            100
        );

        $allowedStatuses = [
            'all',
            'active',
            'inactive',
            'locked',
        ];

        if (!in_array(
            $status,
            $allowedStatuses,
            true
        )) {
            $status = 'all';
        }

        $allowedSorts = [
            'username',
            'display_name',
            'email',
            'last_login_at',
            'created_at',
        ];

        if (!in_array(
            $sort,
            $allowedSorts,
            true
        )) {
            $sort = 'created_at';
        }

        $direction =
            strtolower($direction) === 'asc'
                ? 'asc'
                : 'desc';

        $page = max(1, $page);

        $total = $this->users
            ->administrationCount(
                $this->tenant->companyId(),
                $search,
                $status
            );

        $lastPage = max(
            1,
            (int) ceil(
                $total / self::PAGE_SIZE
            )
        );

        $page = min($page, $lastPage);

        $offset =
            ($page - 1) * self::PAGE_SIZE;

        $users = $this->users
            ->administrationPage(
                $this->tenant->companyId(),
                $search,
                $status,
                $sort,
                $direction,
                self::PAGE_SIZE,
                $offset
            );

        $userIds = array_map(
            static function (array $user): int {
                return (int) $user['user_id'];
            },
            $users
        );

        $roles = $this->users
            ->roleCodesForUsers(
                $this->tenant->companyId(),
                $userIds
            );

        foreach ($users as &$user) {
            $userId = (int) $user['user_id'];

            $user['roles'] =
                $roles[$userId] ?? [];

            $user['is_locked'] =
                $this->isLocked(
                    $user['locked_until'] ?? null
                );
        }

        unset($user);

        return [
            'users' => $users,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'sort' => $sort,
                'direction' => $direction,
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

    private function isLocked(
        mixed $lockedUntil
    ): bool {
        if (
            !is_string($lockedUntil)
            || trim($lockedUntil) === ''
        ) {
            return false;
        }

        $timestamp = strtotime($lockedUntil);

        return $timestamp !== false
            && $timestamp > time();
    }
}
