<?php

declare(strict_types=1);

namespace App\Repositories;

interface AttendancePushSubscriptionRepository
{
    public function countActive(
        int $companyId,
        int $userId
    ): int;

    /** @param array<string, mixed> $values */
    public function upsert(array $values): void;

    public function deactivate(
        int $companyId,
        int $userId,
        string $endpointHash
    ): bool;

    /** @return list<array<string, mixed>> */
    public function pendingDeliveries(
        int $limit = 100
    ): array;

    public function markDelivered(
        int $notificationId,
        int $subscriptionId,
        int $statusCode
    ): void;

    public function markFailed(
        int $notificationId,
        int $subscriptionId,
        int $attempts,
        ?int $statusCode,
        string $reason,
        ?string $nextAttemptAt,
        bool $permanent
    ): void;

    public function disableSubscription(
        int $subscriptionId
    ): void;
}
