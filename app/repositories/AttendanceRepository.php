<?php

declare(strict_types=1);

namespace App\Repositories;

interface AttendanceRepository
{
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
