<?php

declare(strict_types=1);

namespace App\Services;

final class WebhookOutboxMarkerHandler implements IntegrationEventHandler
{
    public function supports(string $eventType): bool
    {
        return in_array($eventType, [
            'sales.order.submitted',
            'sales.order.approved',
            'sales.order.cancelled',
            'sales.credit_hold.created',
            'sales.credit_hold.released',
        ], true);
    }

    public function handle(array $event): void
    {
        // Durable webhook fan-out reads this event after the outbox marks it processed.
    }
}
