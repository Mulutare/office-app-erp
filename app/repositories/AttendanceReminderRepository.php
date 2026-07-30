<?php

declare(strict_types=1);

namespace App\Repositories;

interface AttendanceReminderRepository
{
    /**
     * Return reminder preferences owned by one company user.
     *
     * @return array<string, mixed>|null
     */
    public function findForUser(
        int $companyId,
        int $userId
    ): ?array;

    /**
     * Create or replace the signed-in user's preferences.
     *
     * @param array<string, mixed> $values
     */
    public function saveForUser(
        int $companyId,
        int $userId,
        array $values
    ): void;
}
