<?php

declare(strict_types=1);

namespace App\Repositories;

interface ManagerTeamRepository
{
    /**
     * Return the signed-in user's company reporting context.
     *
     * @return array<string, mixed>|null
     */
    public function reportingContext(
        int $companyId,
        int $userId
    ): ?array;

    /**
     * Return only active users assigned directly to this manager.
     *
     * @return list<array<string, mixed>>
     */
    public function directReports(
        int $companyId,
        int $managerUserId,
        string $attendanceDate
    ): array;
}
