<?php

declare(strict_types=1);

namespace App\Repositories;

interface IntegrationEventRepository
{
    /** @return list<array<string, mixed>> */
    public function claimPending(int $limit, string $workerId): array;

    public function markProcessed(string $eventId, string $workerId): void;

    public function markFailed(
        string $eventId,
        string $error,
        bool $retry,
        string $workerId
    ): void;
}
