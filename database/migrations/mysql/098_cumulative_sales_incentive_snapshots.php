<?php

declare(strict_types=1);

return [
    'version' => '098',
    'description' => 'Cumulative confirmed-sales incentive snapshots with historical float compatibility',
    'statements' => [
        <<<'SQL'
ALTER TABLE sales_incentive_claims
 MODIFY originating_report_id BIGINT UNSIGNED NULL,
 MODIFY float_id BIGINT UNSIGNED NULL,
 ADD COLUMN claim_basis VARCHAR(24) NOT NULL DEFAULT 'legacy_float',
 ADD COLUMN confirmed_sales_snapshot DECIMAL(18,2) NULL,
 ADD COLUMN confirmed_reports_snapshot LONGTEXT NULL,
 ADD COLUMN submission_key CHAR(64) NULL,
 ADD CONSTRAINT uq_incentive_submission UNIQUE(company_id,submission_key),
 ADD CONSTRAINT ck_incentive_basis CHECK(claim_basis IN('legacy_float','cumulative_sales')),
 ADD CONSTRAINT ck_cumulative_incentive_snapshot CHECK(claim_basis='legacy_float' OR
   (originating_report_id IS NULL AND float_id IS NULL AND confirmed_sales_snapshot IS NOT NULL
    AND confirmed_reports_snapshot IS NOT NULL AND submission_key IS NOT NULL))
SQL,
    ],
];
