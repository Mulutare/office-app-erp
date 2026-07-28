<?php

declare(strict_types=1);

namespace App\Repositories;

interface EmployeePositionAssignmentRepository
{
    /**
     * @return array<string, mixed>|null
     */
    public function employee(
        int $companyId,
        int $employeeId,
        bool $lock = false
    ): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    public function history(
        int $companyId,
        int $employeeId
    ): array;

    /**
     * @return array<string, mixed>|null
     */
    public function current(
        int $companyId,
        int $employeeId,
        bool $lock = false
    ): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    public function positionOptions(
        int $companyId
    ): array;

    /**
     * @return array<string, mixed>|null
     */
    public function position(
        int $companyId,
        int $positionId,
        bool $lock = false
    ): ?array;

    public function currentPositionCount(
        int $companyId,
        int $positionId
    ): int;

    public function endAssignment(
        int $companyId,
        int $assignmentId,
        string $effectiveTo,
        int $updatedBy
    ): void;

    /**
     * @param array<string, mixed> $values
     */
    public function create(
        int $companyId,
        int $employeeId,
        int $positionId,
        array $values,
        int $createdBy
    ): int;

    public function synchronizeEmployeeOrganization(
        int $companyId,
        int $employeeId,
        int $departmentId,
        string $jobTitle,
        int $updatedBy
    ): void;
}
