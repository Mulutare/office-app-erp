<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Repositories\DashboardStatisticsRepository;
use App\Repositories\RepositoryFactory;

final class DashboardService
{
    private TenantContext $tenant;
    private DashboardStatisticsRepository $statistics;

    public function __construct(
        ?DashboardStatisticsRepository $statistics = null,
        ?TenantContext $tenant = null
    ) {
        $this->statistics = $statistics
            ?? RepositoryFactory::dashboardStatistics();
        $this->tenant = $tenant
            ?? new TenantContext();
    }

    /**
     * @return array{
     *     users: int,
     *     successfulLogins: int,
     *     failedLogins: int,
     *     securityAlerts: int
     * }
     */
    public function statistics(): array
    {
        return $this->statistics->statistics(
            $this->tenant->companyId()
        );
    }

    public function signedInAccount(array $auth): array
    {
        $userId = (int) ($auth['user_id'] ?? 0);
        $companyId = $this->tenant->companyId();
        $sessions = new AuthenticatedSessionService();
        $user = (new User())->findById($userId) ?? [];
        $canViewDetails = in_array(
            'administration.users.manage',
            is_array($auth['permissions'] ?? null) ? $auth['permissions'] : [],
            true
        );
        return [
            'display_name'=>(string)($auth['display_name']??''),
            'username'=>(string)($auth['username']??''),
            'roles'=>array_values(array_filter($auth['roles']??[],'is_string')),
            'active_sessions'=>$sessions->count($companyId,$userId),
            'last_login_at'=>$user['last_login_at']??null,
            'can_view_sessions'=>$canViewDetails,
            'sessions'=>$canViewDetails?$sessions->list($companyId,$userId):[],
        ];
    }
}
