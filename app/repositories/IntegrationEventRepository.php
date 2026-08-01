<?php

declare(strict_types=1);

namespace App\Repositories;

interface IntegrationEventRepository
{
    /** @return list<array<string, mixed>> */
    public function pending(int $limit): array;

    public function markProcessed(string $eventId): void;

    public function markFailed(
        string $eventId,
        string $error,
        bool $retry
    ): void;
}
