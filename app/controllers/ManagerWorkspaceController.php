<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\CompanyModuleService;
use App\Services\ManagerWorkspaceService;

final class ManagerWorkspaceController
{
    private AuthorizationService $authorization;
    private CompanyModuleService $modules;
    private ManagerWorkspaceService $workspace;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->modules =
            new CompanyModuleService();
        $this->workspace =
            new ManagerWorkspaceService();
    }

    public function index(): void
    {
        $this->authorization
            ->requireModule('hr');
        $this->authorization
            ->requireAnyPermission([
                'hr.records.view',
                'hr.records.manage',
                'hr.leave.view',
                'hr.leave.manage',
                'hr.leave.approve',
                'hr.leave.self.view',
                'hr.leave.self.request',
                'hr.leave.team.approve',
            ]);

        $workspace = $this->workspace->workspace(
            (int) (
                $_SESSION['auth']['user_id'] ?? 0
            ),
            $this->modules->isEnabled(
                'attendance'
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
            'pageTitle' => 'My Team',
            'pageDescription' =>
                'Reporting relationships, team availability, leave balances and manager actions.',
            'contentView' => 'hr.team.index',
            'user' => $_SESSION['auth'],
            'reporting' =>
                $workspace['reporting'],
            'reports' => $workspace['reports'],
            'pendingRequests' =>
                $workspace['pendingRequests'],
            'upcomingRequests' =>
                $workspace['upcomingRequests'],
            'balances' =>
                $workspace['balances'],
            'summary' => $workspace['summary'],
            'attendanceEnabled' =>
                $workspace['attendanceEnabled'],
            'today' => $workspace['today'],
            'canApproveTeam' =>
                $this->hasPermission(
                    'hr.leave.team.approve'
                )
                || $this->hasPermission(
                    'hr.leave.approve'
                ),
        ]);
    }

    private function hasPermission(
        string $permission
    ): bool {
        return in_array(
            $permission,
            $_SESSION['auth']['permissions'] ?? [],
            true
        );
    }
}
