<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrganizationReadinessRepository;
use App\Repositories\RepositoryFactory;
use DateTimeZone;

final class OrganizationSetupService
{
    private const METRIC_KEYS = [
        'branches_total',
        'branches_active',
        'head_offices_active',
        'localized_branches',
        'departments_total',
        'departments_active',
        'job_titles_total',
        'job_titles_active',
        'positions_total',
        'positions_open',
        'positions_planned',
        'approved_headcount',
        'active_employees',
        'assigned_employees',
        'linked_employees',
        'managed_employees',
    ];

    private OrganizationReadinessRepository $readiness;
    private TenantContext $tenant;

    public function __construct(
        ?OrganizationReadinessRepository $readiness = null,
        ?TenantContext $tenant = null
    ) {
        $this->readiness = $readiness
            ?? RepositoryFactory::organizationReadiness();
        $this->tenant = $tenant
            ?? new TenantContext();
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $company = $this->tenant->company();
        $snapshot = $this->readiness->snapshot(
            $this->tenant->companyId()
        );
        $metrics = $this->normalizeMetrics(
            $snapshot
        );
        $company['country_code'] = (string) (
            $snapshot['country_code']
            ?? $company['country_code']
            ?? ''
        );
        $company['default_currency'] = (string) (
            $snapshot['default_currency']
            ?? $company['default_currency']
            ?? ''
        );
        $company['timezone'] = (string) (
            $snapshot['timezone']
            ?? $company['timezone']
            ?? ''
        );
        $companyStandard =
            $this->companyStandard($company);
        $reportingTarget = max(
            0,
            $metrics['linked_employees'] - 1
        );
        $reportingComplete =
            $metrics['linked_employees'] > 0
            && $metrics[
                'managed_employees'
            ] >= $reportingTarget;
        $reportingMetric =
            $metrics['linked_employees'] < 1
                ? 'No linked employee accounts'
                : (
                    $reportingTarget === 0
                        ? 'Top-level leader structure valid'
                        : sprintf(
                            '%d of %d reporting lines',
                            $metrics[
                                'managed_employees'
                            ],
                            $reportingTarget
                        )
                );

        $stages = [
            $this->stage(
                'localization',
                'International operating baseline',
                'Confirm the company country, ISO currency and IANA timezone used for localized transactions and reporting.',
                $companyStandard,
                sprintf(
                    '%s | %s | %s',
                    (string) (
                        $company['country_code']
                        ?? 'Not set'
                    ),
                    (string) (
                        $company['default_currency']
                        ?? 'Not set'
                    ),
                    (string) (
                        $company['timezone']
                        ?? 'Not set'
                    )
                ),
                '',
                'Vendor update required'
            ),
            $this->stage(
                'branches',
                'Locations and legal operating footprint',
                'Maintain active locations with one head office, ISO country codes and local IANA timezones.',
                $metrics['branches_active'] > 0
                    && $metrics[
                        'head_offices_active'
                    ] === 1
                    && $metrics[
                        'localized_branches'
                    ] === $metrics[
                        'branches_active'
                    ],
                sprintf(
                    '%d active | %d head office',
                    $metrics['branches_active'],
                    $metrics[
                        'head_offices_active'
                    ]
                ),
                '/office_app/public/organization/branches',
                'Configure locations'
            ),
            $this->stage(
                'departments',
                'Department hierarchy',
                'Define accountable business units and reporting structures before placing employees.',
                $metrics['departments_active'] > 0,
                sprintf(
                    '%d active departments',
                    $metrics['departments_active']
                ),
                '/office_app/public/organization/departments',
                'Configure departments'
            ),
            $this->stage(
                'job_titles',
                'Global job architecture',
                'Standardize job families, titles and grades for consistent workforce reporting across countries.',
                $metrics['job_titles_active'] > 0,
                sprintf(
                    '%d active job titles',
                    $metrics['job_titles_active']
                ),
                '/office_app/public/organization/job-titles',
                'Configure job titles'
            ),
            $this->stage(
                'positions',
                'Approved positions and headcount',
                'Connect locations, departments and job titles to controlled positions with approved capacity.',
                $metrics['positions_total'] > 0
                    && $metrics[
                        'approved_headcount'
                    ] > 0
                    && (
                        $metrics['positions_open']
                        + $metrics[
                            'positions_planned'
                        ]
                    ) > 0,
                sprintf(
                    '%d positions | %d approved',
                    $metrics['positions_total'],
                    $metrics['approved_headcount']
                ),
                '/office_app/public/organization/positions',
                'Plan positions'
            ),
            $this->stage(
                'placement',
                'Employee position coverage',
                'Give every active employee an effective-dated position so workforce history remains auditable.',
                $metrics['active_employees'] > 0
                    && $metrics[
                        'assigned_employees'
                    ] >= $metrics[
                        'active_employees'
                    ],
                sprintf(
                    '%d of %d placed',
                    $metrics['assigned_employees'],
                    $metrics['active_employees']
                ),
                '/office_app/public/hr',
                'Review employees'
            ),
            $this->stage(
                'reporting',
                'Reporting lines and approvals',
                'Assign managers to employee accounts while allowing one top-level organizational leader.',
                $reportingComplete,
                $reportingMetric,
                '/office_app/public/administration/users',
                'Review reporting lines'
            ),
        ];
        $completed = count(array_filter(
            $stages,
            static fn (array $stage): bool =>
                !empty($stage['complete'])
        ));
        $progress = (int) round(
            ($completed / count($stages)) * 100
        );
        $nextAction = null;

        foreach ($stages as $stage) {
            if (empty($stage['complete'])) {
                $nextAction = $stage;
                break;
            }
        }

        return [
            'company' => [
                'name' => (string) (
                    $company['name'] ?? ''
                ),
                'countryCode' => (string) (
                    $company['country_code'] ?? ''
                ),
                'currency' => (string) (
                    $company['default_currency'] ?? ''
                ),
                'timezone' => (string) (
                    $company['timezone'] ?? ''
                ),
            ],
            'metrics' => $metrics + [
                'vacant_headcount' => max(
                    0,
                    $metrics[
                        'approved_headcount'
                    ] - $metrics[
                        'assigned_employees'
                    ]
                ),
                'placement_coverage' =>
                    $this->percentage(
                        $metrics[
                            'assigned_employees'
                        ],
                        $metrics[
                            'active_employees'
                        ]
                    ),
                'reporting_coverage' =>
                    $reportingComplete
                        ? 100
                        : $this->percentage(
                            $metrics[
                                'managed_employees'
                            ],
                            $reportingTarget
                        ),
            ],
            'stages' => $stages,
            'completedStages' => $completed,
            'totalStages' => count($stages),
            'progress' => $progress,
            'readinessLabel' =>
                $this->readinessLabel($progress),
            'nextAction' => $nextAction,
        ];
    }

    /**
     * @param array<string, int|string|null> $snapshot
     *
     * @return array<string, int>
     */
    private function normalizeMetrics(
        array $snapshot
    ): array {
        $metrics = [];

        foreach (self::METRIC_KEYS as $key) {
            $metrics[$key] = max(
                0,
                (int) ($snapshot[$key] ?? 0)
            );
        }

        return $metrics;
    }

    /**
     * @param array<string, mixed> $company
     */
    private function companyStandard(
        array $company
    ): bool {
        $countryCode = strtoupper(trim(
            (string) (
                $company['country_code'] ?? ''
            )
        ));
        $currency = strtoupper(trim(
            (string) (
                $company['default_currency'] ?? ''
            )
        ));
        $timezone = trim((string) (
            $company['timezone'] ?? ''
        ));

        return preg_match(
            '/^[A-Z]{2}$/',
            $countryCode
        ) === 1
            && preg_match(
                '/^[A-Z]{3}$/',
                $currency
            ) === 1
            && in_array(
                $timezone,
                DateTimeZone::listIdentifiers(),
                true
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function stage(
        string $key,
        string $title,
        string $description,
        bool $complete,
        string $metric,
        string $path,
        string $actionLabel
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'description' => $description,
            'complete' => $complete,
            'statusLabel' => $complete
                ? 'Ready'
                : 'Action required',
            'metric' => $metric,
            'path' => $path,
            'actionLabel' => $actionLabel,
        ];
    }

    private function percentage(
        int $numerator,
        int $denominator
    ): int {
        if ($denominator < 1) {
            return $numerator > 0 ? 100 : 0;
        }

        return min(
            100,
            (int) round(
                ($numerator / $denominator) * 100
            )
        );
    }

    private function readinessLabel(int $progress): string
    {
        if ($progress === 100) {
            return 'Operationally ready';
        }

        if ($progress >= 70) {
            return 'Strong foundation';
        }

        if ($progress >= 40) {
            return 'Setup in progress';
        }

        return 'Foundation required';
    }
}
