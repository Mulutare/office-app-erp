<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Repositories\DepartmentRepository;
use App\Repositories\RepositoryFactory;

final class EmployeeDirectoryService
{
    private const PAGE_SIZE = 20;

    private const STATUSES = [
        'active',
        'on_leave',
        'suspended',
        'terminated',
    ];

    private Employee $employees;
    private DepartmentRepository $departments;
    private TenantContext $tenant;

    public function __construct()
    {
        $this->employees = new Employee();
        $this->departments =
            RepositoryFactory::departments();
        $this->tenant = new TenantContext();
    }

    /**
     * @return array<string, mixed>
     */
    public function directory(
        string $search,
        string $status,
        int $departmentId,
        int $page
    ): array {
        $search = mb_substr(
            trim($search),
            0,
            100
        );
        $status = in_array(
            $status,
            self::STATUSES,
            true
        )
            ? $status
            : '';
        $companyId = $this->tenant->companyId();
        $departments = $this->departments
            ->activeOptions($companyId);
        $validDepartmentIds = array_map(
            static fn (array $department): int =>
                (int) $department['department_id'],
            $departments
        );

        if (!in_array(
            $departmentId,
            $validDepartmentIds,
            true
        )) {
            $departmentId = 0;
        }

        $filters = [
            'search' => $search,
            'status' => $status,
            'departmentId' => $departmentId,
        ];
        $page = max(1, $page);
        $total = $this->employees->count(
            $companyId,
            $filters
        );
        $lastPage = max(
            1,
            (int) ceil($total / self::PAGE_SIZE)
        );
        $page = min($page, $lastPage);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $employees = $this->employees->page(
            $companyId,
            $filters,
            self::PAGE_SIZE,
            $offset
        );

        foreach ($employees as &$employee) {
            $employee = $this->present($employee);
        }

        unset($employee);

        return [
            'employees' => $employees,
            'departments' => $departments,
            'statusOptions' =>
                $this->statusOptions(),
            'summary' =>
                $this->employees->statusSummary(
                    $companyId
                ),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'department' => $departmentId,
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
     * @return array<string, mixed>|null
     */
    public function profile(
        int $employeeId
    ): ?array {
        if ($employeeId < 1) {
            return null;
        }

        $companyId = $this->tenant->companyId();
        $employee = $this->employees->find(
            $companyId,
            $employeeId
        );

        if ($employee === null) {
            return null;
        }

        $reports = $this->employees
            ->directReports(
                $companyId,
                $employeeId
            );

        foreach ($reports as &$report) {
            $report = $this->present($report);
        }

        unset($report);

        return [
            'employee' => $this->present($employee),
            'directReports' => $reports,
        ];
    }

    /**
     * @param array<string, mixed> $employee
     *
     * @return array<string, mixed>
     */
    private function present(array $employee): array
    {
        $firstName = trim((string) (
            $employee['first_name'] ?? ''
        ));
        $middleName = trim((string) (
            $employee['middle_name'] ?? ''
        ));
        $lastName = trim((string) (
            $employee['last_name'] ?? ''
        ));
        $preferredName = trim((string) (
            $employee['preferred_name'] ?? ''
        ));
        $names = array_values(array_filter(
            [
                $firstName,
                $middleName,
                $lastName,
            ],
            static fn (string $name): bool =>
                $name !== ''
        ));
        $status = (string) (
            $employee['employment_status']
            ?? ''
        );
        $type = (string) (
            $employee['employment_type'] ?? ''
        );

        $employee['fullName'] = implode(
            ' ',
            $names
        );
        $employee['displayName'] =
            $preferredName !== ''
                ? $preferredName . ' ' . $lastName
                : $employee['fullName'];
        $employee['statusLabel'] =
            $this->statusLabel($status);
        $employee['statusTone'] =
            $this->statusTone($status);
        $employee['typeLabel'] = ucwords(
            str_replace('_', ' ', $type)
        );

        return $employee;
    }

    /**
     * @return array<string, string>
     */
    private function statusOptions(): array
    {
        $options = [];

        foreach (self::STATUSES as $status) {
            $options[$status] =
                $this->statusLabel($status);
        }

        return $options;
    }

    private function statusLabel(string $status): string
    {
        return ucwords(str_replace(
            '_',
            ' ',
            $status
        ));
    }

    private function statusTone(string $status): string
    {
        if ($status === 'active') {
            return 'success';
        }

        if ($status === 'terminated') {
            return 'danger';
        }

        if (
            $status === 'on_leave'
            || $status === 'suspended'
        ) {
            return 'warning';
        }

        return 'muted';
    }
}
