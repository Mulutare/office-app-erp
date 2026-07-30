<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\LeaveRepository;
use App\Repositories\ManagerTeamRepository;
use App\Repositories\RepositoryFactory;

final class ManagerWorkspaceService
{
    private const ATTENDANCE_STATUSES = [
        'present' => ['Present', 'success'],
        'late' => ['Late', 'warning'],
        'absent' => ['Absent', 'danger'],
        'remote' => ['Remote', 'info'],
        'on_leave' => ['On leave', 'info'],
        'holiday' => ['Holiday', 'muted'],
    ];

    private ManagerTeamRepository $teams;
    private LeaveRepository $leave;
    private TenantContext $tenant;

    public function __construct(
        ?ManagerTeamRepository $teams = null,
        ?LeaveRepository $leave = null,
        ?TenantContext $tenant = null
    ) {
        $this->teams = $teams
            ?? RepositoryFactory::managerTeams();
        $this->leave = $leave
            ?? RepositoryFactory::leave();
        $this->tenant = $tenant
            ?? new TenantContext();
    }

    /**
     * @return array<string, mixed>
     */
    public function workspace(
        int $actorUserId,
        bool $attendanceEnabled
    ): array {
        $companyId = $this->tenant->companyId();
        $today = date('Y-m-d');
        $reporting = $this->teams
            ->reportingContext(
                $companyId,
                $actorUserId
            );

        if ($reporting === null) {
            throw new \RuntimeException(
                'An active company membership is required.'
            );
        }

        $reports = $this->teams->directReports(
            $companyId,
            $actorUserId,
            $today
        );
        $requests = $this->leave
            ->requestsForManager(
                $companyId,
                $actorUserId
            );
        $pendingRequests = [];
        $upcomingRequests = [];

        foreach ($requests as $request) {
            $status = (string) (
                $request['request_status'] ?? ''
            );

            if ($status === 'pending') {
                $pendingRequests[] =
                    $this->presentRequest($request);
            }

            if (
                $status === 'approved'
                && substr(
                    (string) (
                        $request['end_date'] ?? ''
                    ),
                    0,
                    10
                ) >= $today
            ) {
                $upcomingRequests[] =
                    $this->presentRequest($request);
            }
        }

        $profileMissingCount = 0;
        $attendanceRecorded = 0;
        $onLeaveToday = 0;

        foreach ($reports as &$report) {
            $report = $this->presentReport($report);

            if (!$report['profileLinked']) {
                $profileMissingCount++;
            }

            if (
                $attendanceEnabled
                && $report['attendanceRecorded']
            ) {
                $attendanceRecorded++;
            }

            if (
                ($report['attendanceStatus'] ?? '')
                === 'on_leave'
            ) {
                $onLeaveToday++;
            }
        }

        unset($report);

        $balances = [];
        $employeeId = (int) (
            $reporting['employee_id'] ?? 0
        );

        if ($employeeId > 0) {
            $balances = $this->presentBalances(
                $this->leave->balancesForEmployee(
                    $companyId,
                    $employeeId,
                    date('Y-01-01'),
                    date('Y-12-31')
                )
            );
        }

        $reporting['displayName'] =
            $this->employeeName(
                $reporting,
                (string) (
                    $reporting['display_name'] ?? ''
                )
            );

        return [
            'reporting' => $reporting,
            'reports' => $reports,
            'pendingRequests' => array_slice(
                $pendingRequests,
                0,
                8
            ),
            'upcomingRequests' => array_slice(
                $upcomingRequests,
                0,
                6
            ),
            'balances' => $balances,
            'attendanceEnabled' =>
                $attendanceEnabled,
            'today' => $today,
            'summary' => [
                'directReports' => count($reports),
                'pendingApprovals' =>
                    count($pendingRequests),
                'attendanceRecorded' =>
                    $attendanceRecorded,
                'onLeaveToday' => $onLeaveToday,
                'profilesMissing' =>
                    $profileMissingCount,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function presentReport(
        array $report
    ): array {
        $profileLinked = (int) (
            $report['employee_id'] ?? 0
        ) > 0;
        $status = (string) (
            $report['attendance_status'] ?? ''
        );
        $attendance = self::ATTENDANCE_STATUSES[
            $status
        ] ?? ['Not recorded', 'muted'];

        $report['displayName'] =
            $this->employeeName(
                $report,
                (string) (
                    $report['display_name'] ?? ''
                )
            );
        $report['profileLinked'] = $profileLinked;
        $report['attendanceRecorded'] =
            $status !== '';
        $report['attendanceStatus'] = $status;
        $report['attendanceLabel'] =
            $attendance[0];
        $report['attendanceTone'] =
            $attendance[1];

        return $report;
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function presentRequest(
        array $request
    ): array {
        $request['employeeName'] =
            $this->employeeName($request, 'Employee');

        return $request;
    }

    /**
     * @param list<array<string, mixed>> $balances
     *
     * @return list<array<string, mixed>>
     */
    private function presentBalances(
        array $balances
    ): array {
        foreach ($balances as &$balance) {
            $entitlement = (float) (
                $balance['annual_entitlement'] ?? 0
            );
            $used = (float) (
                $balance['used_days'] ?? 0
            );
            $carryOver = (float) (
                $balance['carry_over_days'] ?? 0
            );
            $adjustments = (float) (
                $balance['adjustment_days'] ?? 0
            );
            $available =
                $entitlement
                + $carryOver
                + $adjustments;

            $balance['baseEntitlementDays'] =
                number_format(
                    $entitlement,
                    2,
                    '.',
                    ''
                );
            $balance['carryOverDays'] =
                number_format(
                    $carryOver,
                    2,
                    '.',
                    ''
                );
            $balance['adjustmentDays'] =
                number_format(
                    $adjustments,
                    2,
                    '.',
                    ''
                );
            $balance['entitlementDays'] =
                number_format(
                    $available,
                    2,
                    '.',
                    ''
                );
            $balance['usedDays'] = number_format(
                $used,
                2,
                '.',
                ''
            );
            $balance['remainingDays'] =
                number_format(
                    max(0, $available - $used),
                    2,
                    '.',
                    ''
                );
            $balance['utilization'] =
                $available > 0
                    ? min(
                        100,
                        (int) round(
                            ($used / $available)
                            * 100
                        )
                    )
                    : 0;
        }

        unset($balance);

        return $balances;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function employeeName(
        array $record,
        string $fallback
    ): string {
        $preferred = trim((string) (
            $record['preferred_name'] ?? ''
        ));
        $first = $preferred !== ''
            ? $preferred
            : trim((string) (
                $record['first_name'] ?? ''
            ));
        $name = trim(
            $first . ' ' . (string) (
                $record['last_name'] ?? ''
            )
        );

        return $name !== '' ? $name : $fallback;
    }
}
