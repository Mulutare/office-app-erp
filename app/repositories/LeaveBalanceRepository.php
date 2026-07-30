<?php

declare(strict_types=1);

namespace App\Repositories;

interface LeaveBalanceRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public function employeeOptions(
        int $companyId
    ): array;

    /**
     * @return array<string, mixed>|null
     */
    public function employee(
        int $companyId,
        int $employeeId
    ): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function policy(
        int $companyId,
        int $leaveTypeId
    ): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function allocation(
        int $companyId,
        int $employeeId,
        int $leaveTypeId,
        int $year
    ): ?array;

    /**
     * @param array<string, mixed> $values
     */
    public function saveAllocation(
        int $companyId,
        int $employeeId,
        int $leaveTypeId,
        int $year,
        array $values,
        int $updatedBy
    ): int;

    /**
     * @param array<string, mixed> $values
     */
    public function addAdjustment(
        int $companyId,
        int $allocationId,
        array $values,
        int $createdBy
    ): int;

    /**
     * @return list<array<string, mixed>>
     */
    public function adjustments(
        int $companyId,
        int $employeeId,
        int $year
    ): array;
}
