<?php

declare(strict_types=1);

namespace App\Repositories;

interface AttendanceRepository
{
    /**
     * Return the active employee profile linked to a company user.
     *
     * @return array<string, mixed>|null
     */
    public function employeeForUser(
        int $companyId,
        int $userId
    ): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    public function historyForEmployee(
        int $companyId,
        int $employeeId,
        string $fromDate,
        string $toDate
    ): array;

    /**
     * Return attendance history only for directly managed users.
     *
     * @return list<array<string, mixed>>
     */
    public function historyForManager(
        int $companyId,
        int $managerUserId,
        string $fromDate,
        string $toDate
    ): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function dailyRoster(
        int $companyId,
        string $attendanceDate
    ): array;

    /**
     * @return array<string, mixed>|null
     */
    public function find(
        int $companyId,
        int $employeeId,
        string $attendanceDate
    ): ?array;

    public function employeeExists(
        int $companyId,
        int $employeeId
    ): bool;

    /**
     * @param array<string, mixed> $values
     */
    public function save(
        int $companyId,
        int $employeeId,
        string $attendanceDate,
        array $values,
        int $updatedBy
    ): int;
}
