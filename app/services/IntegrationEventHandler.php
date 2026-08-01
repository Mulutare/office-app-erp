<?php

declare(strict_types=1);

namespace App\Services;

interface IntegrationEventHandler
{
    public function supports(string $eventType): bool;

    /** @param array<string, mixed> $event */
    public function handle(array $event): void;
}
