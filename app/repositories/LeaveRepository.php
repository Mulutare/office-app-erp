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
     * Return the complete tenant policy catalogue, including inactive types.
     *
     * @return list<array<string, mixed>>
     */
    public function leaveTypeCatalog(
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

    /**
     * @return array<string, mixed>|null
     */
    public function leaveTypeForManagement(
        int $companyId,
        int $leaveTypeId
    ): ?array;

    public function leaveTypeCodeExists(
        int $companyId,
        string $code,
        ?int $ignoreLeaveTypeId = null
    ): bool;

    public function leaveTypeNameExists(
        int $companyId,
        string $name,
        ?int $ignoreLeaveTypeId = null
    ): bool;

    /**
     * @param array<string, mixed> $values
     */
    public function createLeaveType(
        int $companyId,
        array $values,
        int $createdBy
    ): int;

    /**
     * @param array<string, mixed> $values
     */
    public function updateLeaveType(
        int $companyId,
        int $leaveTypeId,
        array $values,
        int $updatedBy
    ): bool;

    public function leaveTypeHasPendingRequests(
        int $companyId,
        int $leaveTypeId
    ): bool;

    public function employeeExists(
        int $companyId,
        int $employeeId
    ): bool;

    /**
     * @return array<string, mixed>|null
     */
    public function employeeForUser(
        int $companyId,
        int $userId
    ): ?array;

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
     * @return list<array<string, mixed>>
     */
    public function requestsForEmployee(
        int $companyId,
        int $employeeId,
        string $status = ''
    ): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function requestsForManager(
        int $companyId,
        int $managerUserId,
        string $status = ''
    ): array;

    /**
     * Return calendar-year entitlement usage for one employee.
     *
     * @return list<array<string, mixed>>
     */
    public function balancesForEmployee(
        int $companyId,
        int $employeeId,
        string $yearStart,
        string $yearEnd
    ): array;

    public function managerCanDecide(
        int $companyId,
        int $managerUserId,
        int $leaveRequestId
    ): bool;

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
