<?php

declare(strict_types=1);

return [
    'version' => '032',
    'description' => 'Add atomic worker leases to API webhook deliveries',
    'preflight' => static function (\PDO $connection): string {
        $columns = (int) $connection->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema=DATABASE() AND table_name='api_webhook_deliveries'
               AND column_name IN ('claimed_by','claimed_at')"
        )->fetchColumn();
        if ($columns === 0) { return 'apply'; }
        if ($columns === 2) { return 'baseline'; }
        throw new \RuntimeException('Migration 032 found partial webhook claim columns.');
    },
    'statements' => [
        <<<'SQL'
ALTER TABLE api_webhook_deliveries
    ADD COLUMN claimed_by VARCHAR(80) NULL AFTER available_at,
    ADD COLUMN claimed_at DATETIME NULL AFTER claimed_by,
    ADD INDEX idx_webhook_delivery_claim (status, available_at, claimed_at)
SQL,
    ],
];
