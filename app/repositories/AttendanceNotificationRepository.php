<?php

declare(strict_types=1);

namespace App\Repositories;

interface AttendanceNotificationRepository
{
    /** @return list<array<string, mixed>> */
    public function reminderCandidates(): array;

    /**
     * Insert one deduplicated durable notification.
     *
     * @param array<string, mixed> $values
     */
    public function create(array $values): bool;

    /** @return list<array<string, mixed>> */
    public function inbox(
        int $companyId,
        int $userId,
        int $limit = 8
    ): array;

    public function markRead(
        int $companyId,
        int $userId,
        int $notificationId
    ): bool;
}
