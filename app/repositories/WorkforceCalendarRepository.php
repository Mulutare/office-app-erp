<?php

declare(strict_types=1);

namespace App\Repositories;

interface WorkforceCalendarRepository
{
    /** @return list<array<string, mixed>> */
    public function calendars(int $companyId): array;

    /** @return array<string, mixed>|null */
    public function calendar(
        int $companyId,
        int $calendarId
    ): ?array;

    /** @param array<string, mixed> $values */
    public function createCalendar(
        int $companyId,
        array $values,
        int $actorUserId
    ): int;

    public function clearDefault(int $companyId): void;

    /** @return list<array<string, mixed>> */
    public function days(
        int $companyId,
        int $calendarId
    ): array;

    /** @param array<string, mixed> $values */
    public function saveDay(
        int $companyId,
        int $calendarId,
        int $isoWeekday,
        array $values
    ): void;

    /** @return list<array<string, mixed>> */
    public function holidays(
        int $companyId,
        int $calendarId,
        string $fromDate,
        string $toDate
    ): array;

    /** @param array<string, mixed> $values */
    public function addHoliday(
        int $companyId,
        int $calendarId,
        array $values,
        int $actorUserId
    ): int;

    /** @return list<array<string, mixed>> */
    public function employeeOptions(int $companyId): array;

    /** @return list<array<string, mixed>> */
    public function assignments(int $companyId): array;

    public function scheduleOverlaps(
        int $companyId,
        int $employeeId,
        string $effectiveFrom,
        ?string $effectiveTo
    ): bool;

    public function assignSchedule(
        int $companyId,
        int $employeeId,
        int $calendarId,
        string $effectiveFrom,
        ?string $effectiveTo,
        int $actorUserId
    ): int;

    /**
     * Resolve the employee's effective calendar, weekday rule and holiday.
     *
     * @return array<string, mixed>|null
     */
    public function contextForUser(
        int $companyId,
        int $userId,
        string $localDate
    ): ?array;
}
