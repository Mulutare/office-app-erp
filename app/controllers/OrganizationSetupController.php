<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthorizationService;
use App\Services\OrganizationSetupService;

final class OrganizationSetupController
{
    private const ACCESS_PERMISSIONS = [
        'organization.branches.view',
        'organization.branches.manage',
        'organization.job_titles.view',
        'organization.job_titles.manage',
        'organization.departments.view',
        'organization.departments.manage',
        'organization.positions.view',
        'organization.positions.manage',
        'hr.records.view',
        'hr.records.manage',
    ];

    private AuthorizationService $authorization;
    private OrganizationSetupService $setup;

    public function __construct()
    {
        $this->authorization =
            new AuthorizationService();
        $this->setup =
            new OrganizationSetupService();
    }

    public function index(): void
    {
        $this->authorization
            ->requireAnyTenantPermission(
                self::ACCESS_PERMISSIONS
            );
        $overview = $this->setup->overview();

        \view('layouts.app', [
            'applicationName' => \config(
                'name',
                'OfficeApp ERP'
            ),
            'environment' => \config(
                'environment',
                'unknown'
            ),
            'pageTitle' =>
                'Organization Setup Center',
            'pageDescription' =>
                'Measure organization readiness and complete the company workforce foundation in the correct order.',
            'contentView' =>
                'organization.setup',
            'user' => $_SESSION['auth'],
            'overview' => $overview,
            'capabilities' =>
                $this->capabilities(),
        ]);
    }

    /**
     * @return array<string, bool>
     */
    private function capabilities(): array
    {
        $permissions = is_array(
            $_SESSION['auth']['permissions'] ?? null
        )
            ? $_SESSION['auth']['permissions']
            : [];

        return [
            'branches' => $this->canAny(
                $permissions,
                [
                    'organization.branches.view',
                    'organization.branches.manage',
                ]
            ),
            'departments' => $this->canAny(
                $permissions,
                [
                    'organization.departments.view',
                    'organization.departments.manage',
                ]
            ),
            'job_titles' => $this->canAny(
                $permissions,
                [
                    'organization.job_titles.view',
                    'organization.job_titles.manage',
                ]
            ),
            'positions' => $this->canAny(
                $permissions,
                [
                    'organization.positions.view',
                    'organization.positions.manage',
                ]
            ),
            'placement' => $this->canAny(
                $permissions,
                [
                    'hr.records.view',
                    'hr.records.manage',
                ]
            ),
            'reporting' => in_array(
                'administration.users.manage',
                $permissions,
                true
            ),
        ];
    }

    /**
     * @param list<string> $permissions
     * @param list<string> $required
     */
    private function canAny(
        array $permissions,
        array $required
    ): bool {
        foreach ($required as $permission) {
            if (in_array(
                $permission,
                $permissions,
                true
            )) {
                return true;
            }
        }

        return false;
    }
}
