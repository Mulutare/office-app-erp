<?php

declare(strict_types=1);

namespace App\Repositories;

interface LeaveRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public function leaveTypes(
        int $companyId
    ): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function employeeOptions(
        int $companyId
    ): array;

    /**
     * @return array<string, mixed>|null
     */
    public function leaveType(
        int $companyId,
        int $leaveTypeId
    ): ?array;

    public function employeeExists(
        int $companyId,
        int $employeeId
    ): bool;

    public function overlaps(
        int $companyId,
        int $employeeId,
        string $startDate,
        string $endDate
    ): bool;

    /**
     * @return list<array<string, mixed>>
     */
    public function requests(
        int $companyId,
        string $status = ''
    ): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findRequest(
        int $companyId,
        int $leaveRequestId,
        bool $lock = false
    ): ?array;

    /**
     * @param array<string, mixed> $values
     */
    public function createRequest(
        int $companyId,
        array $values,
        int $createdBy
    ): int;

    public function decide(
        int $companyId,
        int $leaveRequestId,
        string $status,
        ?string $decisionNote,
        int $decidedBy
    ): bool;

    public function provisionDefaultTypes(
        int $companyId,
        int $createdBy
    ): void;
}
