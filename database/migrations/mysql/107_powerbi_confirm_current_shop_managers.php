<?php

declare(strict_types=1);

return [
    'version' => '107',
    'description' => 'Confirm the 21 business-verified current Power BI shop-manager assignments while preserving unresolved history and Safaricom semantic gates',
    'preflight' => static function(\PDO $connection): string {
        $required = [
            'bi_powerbi_shop_manager_assignments',
            'vw_powerbi_current_shop_manager_scope',
            'vw_powerbi_cutover_blockers',
            'vw_powerbi_reporting_readiness'
        ];
        $quoted = implode(',', array_map(
            static fn(string $name): string => $connection->quote($name),
            $required
        ));
        $count = (int)$connection->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name IN($quoted)"
        )->fetchColumn();

        if ($count !== count($required)) {
            throw new \RuntimeException(
                'Migration 107 requires the Power BI manager-governance and readiness layer.'
            );
        }

        $pendingAll = (int)$connection->query(
            "SELECT COUNT(*) FROM bi_powerbi_shop_manager_assignments
             WHERE company_id=2 AND status='pending'"
        )->fetchColumn();

        $pendingExpected = (int)$connection->query(
            "SELECT COUNT(*) FROM bi_powerbi_shop_manager_assignments
             WHERE company_id=2 AND status='pending'
               AND (warehouse_id,employee_id) IN ((2,90),(3,101),(4,36),(6,104),(5,97),(7,106),(8,77),(9,102),(10,92),(11,96),(12,109),(13,103),(14,95),(15,107),(16,99),(17,98),(18,108),(19,93),(20,100),(21,94),(22,91))"
        )->fetchColumn();

        $confirmedExpected = (int)$connection->query(
            "SELECT COUNT(*) FROM bi_powerbi_shop_manager_assignments
             WHERE company_id=2 AND status='confirmed'
               AND mapping_source='BUSINESS_CONFIRMED_CURRENT_MANAGER_2026_09_30'
               AND (warehouse_id,employee_id) IN ((2,90),(3,101),(4,36),(6,104),(5,97),(7,106),(8,77),(9,102),(10,92),(11,96),(12,109),(13,103),(14,95),(15,107),(16,99),(17,98),(18,108),(19,93),(20,100),(21,94),(22,91))"
        )->fetchColumn();

        $sentinel = (int)$connection->query(
            "SELECT COUNT(*) FROM information_schema.views
             WHERE table_schema=DATABASE()
               AND table_name='vw_powerbi_107_manager_confirmation_audit'"
        )->fetchColumn();

        if ($pendingAll === 21 && $pendingExpected === 21
            && $confirmedExpected === 0 && $sentinel === 0) {
            return 'apply';
        }

        if ($pendingAll === 0 && $confirmedExpected === 21 && $sentinel === 1) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 107 found unexpected or partially confirmed shop-manager assignments.'
        );
    },
    'statements' => [
        <<<'SQL'
UPDATE bi_powerbi_shop_manager_assignments
SET status='confirmed',
    effective_from='2026-08-01',
    effective_to=NULL,
    mapping_source='BUSINESS_CONFIRMED_CURRENT_MANAGER_2026_09_30',
    notes='Business confirmed on 2026-09-30 that the seeded shop-manager candidate is the current manager and there has been no manager change from the August 2026 Power BI period through confirmation date.',
    confirmed_at=NOW()
WHERE company_id=2
  AND status='pending'
  AND (warehouse_id,employee_id) IN ((2,90),(3,101),(4,36),(6,104),(5,97),(7,106),(8,77),(9,102),(10,92),(11,96),(12,109),(13,103),(14,95),(15,107),(16,99),(17,98),(18,108),(19,93),(20,100),(21,94),(22,91))
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_107_manager_confirmation_audit AS
SELECT 2 AS company_id,
       SUM(a.status='confirmed'
           AND a.mapping_source='BUSINESS_CONFIRMED_CURRENT_MANAGER_2026_09_30') AS business_confirmed_current_managers,
       SUM(a.status='pending') AS pending_manager_assignments,
       MIN(CASE
             WHEN a.status='confirmed'
              AND a.mapping_source='BUSINESS_CONFIRMED_CURRENT_MANAGER_2026_09_30'
             THEN a.effective_from
           END) AS min_confirmed_effective_from,
       MAX(CASE
             WHEN a.status='confirmed'
              AND a.mapping_source='BUSINESS_CONFIRMED_CURRENT_MANAGER_2026_09_30'
             THEN a.confirmed_at
           END) AS latest_confirmation_at
FROM bi_powerbi_shop_manager_assignments a
WHERE a.company_id=2
SQL,
    ],
];
