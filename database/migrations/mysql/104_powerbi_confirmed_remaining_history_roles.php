<?php

declare(strict_types=1);

return [
    'version' => '104',
    'description' => 'Record verified August 2026 Power BI roles for Rahel Beyene Yihdegela, Michael Tsehaye and NB while leaving Mussie Yohannes Tsegay unresolved',
    'preflight' => static function(\PDO $connection): string {
        $required = [
            'bi_powerbi_history_role_overrides',
            'vw_powerbi_history_stock_current',
            'vw_powerbi_history_employee_role_effective',
            'vw_powerbi_compat_stock_detail',
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
                'Migration 104 requires the complete migration 103 Power BI history-role layer.'
            );
        }

        $sentinel = (int)$connection->query(
            "SELECT COUNT(*) FROM information_schema.views
             WHERE table_schema=DATABASE()
               AND table_name='vw_powerbi_104_verified_history_roles_audit'"
        )->fetchColumn();

        if ($sentinel === 1) {
            return 'baseline';
        }

        return 'apply';
    },
    'statements' => [
        <<<'SQL'
INSERT INTO bi_powerbi_history_role_overrides
    (company_id,legacy_employee_name,canonical_employee_name,role,role_group,
     effective_from,effective_to,status,evidence_source,notes)
VALUES
(2,'Rahel Beyene Yihdegela','Rahel Beyene Yihdegela','DSP','DSA/DSP','2026-08-01','2026-08-26','confirmed','BUSINESS_ROLE_CONFIRMATION_2026_09_30','Business supplied role evidence identifying Rahel Beyene Yihdegela as DSP for the August 2026 historical reporting period.'),
(2,'Michael Tsehaye','Michael Tsehaye','District Manager','District Manager','2026-08-01','2026-08-26','confirmed','BUSINESS_ROLE_CONFIRMATION_2026_09_30','Business supplied role evidence identifying Michael Tsehaye as District Manager for the August 2026 historical reporting period.'),
(2,'NB','NB','District Manager','District Manager','2026-08-01','2026-08-26','confirmed','BUSINESS_ROLE_CONFIRMATION_2026_09_30','Business supplied role evidence identifying NB as District Manager for the August 2026 historical reporting period. NB is preserved exactly as represented in the legacy Power BI data; no person identity is inferred.')
ON DUPLICATE KEY UPDATE
    canonical_employee_name=VALUES(canonical_employee_name),
    role=VALUES(role),
    status=VALUES(status),
    evidence_source=VALUES(evidence_source),
    notes=VALUES(notes),
    confirmed_at=CURRENT_TIMESTAMP
SQL,
        <<<'SQL'
CREATE OR REPLACE VIEW vw_powerbi_104_verified_history_roles_audit AS
SELECT o.company_id,o.legacy_employee_name,o.canonical_employee_name,
       o.role,o.role_group,o.effective_from,o.effective_to,o.status,
       o.evidence_source,
       COUNT(h.source_row_number) AS matching_history_rows,
       MIN(h.report_date) AS matching_min_date,
       MAX(h.report_date) AS matching_max_date
FROM bi_powerbi_history_role_overrides o
LEFT JOIN vw_powerbi_history_stock_current h
  ON h.company_id=o.company_id
 AND CONVERT(LOWER(TRIM(h.employee_name)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
     = CONVERT(LOWER(TRIM(o.legacy_employee_name)) USING utf8mb4) COLLATE utf8mb4_unicode_ci
 AND h.report_date BETWEEN o.effective_from AND o.effective_to
WHERE o.company_id=2
  AND o.status='confirmed'
  AND o.evidence_source='BUSINESS_ROLE_CONFIRMATION_2026_09_30'
  AND o.legacy_employee_name IN('Rahel Beyene Yihdegela','Michael Tsehaye','NB')
GROUP BY o.company_id,o.legacy_employee_name,o.canonical_employee_name,
         o.role,o.role_group,o.effective_from,o.effective_to,o.status,o.evidence_source
SQL,
    ],
];
