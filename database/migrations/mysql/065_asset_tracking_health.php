<?php

declare(strict_types=1);

return [
    'version' => '065',
    'description' => 'Add authoritative asset presence, health and verification tracking',
    'preflight' => static function (\PDO $connection): string {
        $statement = $connection->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema=DATABASE()
               AND table_name='fixed_assets'
               AND column_name IN ('presence_status','health_status','last_verified_at','last_verified_by')"
        );
        $count = (int) $statement->fetchColumn();
        if ($count === 0) return 'apply';
        if ($count === 4) return 'baseline';
        throw new \RuntimeException('Migration 065 found a partial Asset tracking schema.');
    },
    'statements' => [
        <<<'SQL'
ALTER TABLE fixed_assets
 ADD COLUMN presence_status VARCHAR(20) NOT NULL DEFAULT 'present' AFTER status,
 ADD COLUMN health_status VARCHAR(20) NOT NULL DEFAULT 'unknown' AFTER presence_status,
 ADD COLUMN last_verified_at DATETIME NULL AFTER health_status,
 ADD COLUMN last_verified_by BIGINT UNSIGNED NULL AFTER last_verified_at,
 ADD CONSTRAINT ck_fixed_asset_presence CHECK(presence_status IN('present','assigned','missing','under_repair')),
 ADD CONSTRAINT ck_fixed_asset_health CHECK(health_status IN('unknown','good','attention','critical')),
 ADD CONSTRAINT fk_fixed_asset_last_verifier FOREIGN KEY(last_verified_by) REFERENCES users(user_id) ON DELETE SET NULL,
 ADD INDEX idx_fixed_asset_tracking(company_id,presence_status,health_status)
SQL,
    ],
];
