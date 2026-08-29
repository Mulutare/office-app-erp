<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\InventoryRepository;
use App\Repositories\RepositoryFactory;
use RuntimeException;

final class InventorySalesIntegrationHandler
    implements IntegrationEventHandler
{
    public function __construct(
        private ?InventoryRepository $inventory = null
    ) {
        $this->inventory ??=
            RepositoryFactory::inventory();
    }

    public function supports(string $eventType): bool
    {
        return in_array(
            $eventType,
            [
                'sales.order.confirmed',
                'sales.order.cancelled',
                'sales.order.fulfilled',
            ],
            true
        );
    }

    /** @param array<string, mixed> $event */
    public function handle(array $event): void
    {
        $companyId = (int) (
            $event['company_id'] ?? 0
        );
        $eventType = (string) (
            $event['event_type'] ?? ''
        );
        $payload = $event['payload'] ?? null;

        if (
            $companyId <= 0
            || !is_array($payload)
        ) {
            throw new RuntimeException(
                'The inventory integration event is invalid.'
            );
        }

        $orderId = (int) (
            $payload['order_id'] ?? 0
        );

        if ($orderId <= 0) {
            throw new RuntimeException(
                'The inventory integration event has no valid sales order.'
            );
        }

        $occurredAt = date('Y-m-d H:i:s');

        if ($eventType === 'sales.order.cancelled') {
            $this->inventory
                ->releaseSalesOrderReservation(
                    $companyId,
                    $orderId,
                    $occurredAt
                );

            return;
        }

        if ($eventType === 'sales.order.fulfilled') {
            $actorId = (int) (
                $payload['actor_id'] ?? 0
            );

            if ($actorId <= 0) {
                throw new RuntimeException(
                    'Sales fulfilment requires a valid posting user.'
                );
            }

            $this->inventory->completeSalesOrderDeliveries(
                $companyId,
                $orderId,
                $actorId,
                $occurredAt
            );

            return;
        }

        $lines = $payload['lines'] ?? null;

        if (!is_array($lines) || $lines === []) {
            throw new RuntimeException(
                'The confirmed sales order has no reservable lines.'
            );
        }

        $warehouseId = (int) ($payload['warehouse_id'] ?? 0);
        $sourceLocationId = (int) ($payload['source_location_id'] ?? 0);
        if ($warehouseId <= 0 || $sourceLocationId <= 0) {
            throw new RuntimeException('The confirmed Sales Order has no explicit fulfillment source.');
        }

        $this->inventory->reserveSalesOrder(
            $companyId,
            $orderId,
            $warehouseId,
            $sourceLocationId,
            $lines,
            $occurredAt
        );
        $this->inventory->ensureDeliveryPickings(
            $companyId,
            $orderId,
            (int) ($payload['actor_id'] ?? 0),
            $occurredAt
        );
    }
}
