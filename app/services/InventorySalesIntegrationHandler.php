<?php

declare(strict_types=1);

namespace App\Services;

final class InventorySalesIntegrationHandler implements IntegrationEventHandler
{
    public function supports(string $eventType): bool
    {
        return $eventType === 'sales.order.confirmed';
    }

    public function handle(array $event): void
    {
        $statement = \db()->prepare(
            "INSERT INTO inventory_sales_commitments
                (company_id, order_id, product_id, quantity, status, reserved_at)
             VALUES
                (:company_id, :order_id, :product_id, :quantity, 'reserved', NOW())
             ON DUPLICATE KEY UPDATE
                quantity = VALUES(quantity),
                status = IF(status = 'fulfilled', status, 'reserved')"
        );
        foreach ($event['payload']['lines'] as $line) {
            $statement->execute([
                'company_id' => $event['company_id'],
                'order_id' => $event['payload']['order_id'],
                'product_id' => $line['product_id'],
                'quantity' => $line['quantity'],
            ]);
        }
    }
}
