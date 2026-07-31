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

    /**
     * Return and lock a daily attendance summary during a punch transaction.
     *
     * @return array<string, mixed>|null
     */
    public function findForUpdate(
        int $companyId,
        int $employeeId,
        string $attendanceDate
    ): ?array;

    /**
     * Return active work sessions in chronological order.
     *
     * @return list<array<string, mixed>>
     */
    public function sessionsForRecord(
        int $companyId,
        int $attendanceId
    ): array;

    /**
     * Return the current active session, if the employee is clocked in.
     *
     * @return array<string, mixed>|null
     */
    public function openSession(
        int $companyId,
        int $attendanceId
    ): ?array;

    public function startSession(
        int $companyId,
        int $attendanceId,
        int $employeeId,
        string $checkInAt,
        string $source,
        int $actorUserId
    ): int;

    public function finishSession(
        int $companyId,
        int $attendanceId,
        int $sessionId,
        string $checkOutAt,
        int $actorUserId
    ): bool;

    /**
     * Maintain one calculated first/latest interval while retaining replaced
     * legacy session rows as inactive evidence.
     */
    public function syncFirstLastSession(
        int $companyId,
        int $attendanceId,
        int $employeeId,
        string $checkInAt,
        ?string $checkOutAt,
        string $source,
        int $actorUserId
    ): int;

    /** @return array<string, mixed>|null */
    public function scanEventByRequestKey(
        int $companyId,
        int $employeeId,
        string $requestKey
    ): ?array;

    /** @param array<string, mixed> $values */
    public function appendScanEvent(
        int $companyId,
        int $employeeId,
        array $values
    ): int;

    /** @return list<array<string, mixed>> */
    public function scanEventsForRecord(
        int $companyId,
        int $attendanceId
    ): array;

    /**
     * Preserve old punch rows as inactive evidence and create the effective
     * manual interval represented by an HR attendance correction.
     */
    public function replaceSessionsForManualRecord(
        int $companyId,
        int $attendanceId,
        int $employeeId,
        ?string $checkInAt,
        ?string $checkOutAt,
        int $actorUserId
    ): void;

    public function employeeExists(
        int $companyId,
        int $employeeId
    ): bool;

    /** Serialize attendance mutations for one tenant employee. */
    public function lockEmployee(
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
