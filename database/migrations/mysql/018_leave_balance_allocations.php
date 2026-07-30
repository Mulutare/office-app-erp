<?php

declare(strict_types=1);

return [
    'version' => '018',
    'description' =>
        'Create tenant leave allocations and adjustment ledger',
    'preflight' => static function (
        \PDO $connection
    ): string {
        $statement = $connection->query(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN (
                    \'hr_leave_allocations\',
                    \'hr_leave_balance_adjustments\'
               )'
        );
        $tableCount = (int) $statement->fetchColumn();

        if ($tableCount === 0) {
            return 'apply';
        }

        if ($tableCount === 2) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 018 found a partial leave-balance schema.'
        );
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE hr_leave_allocations (
    allocation_id BIGINT UNSIGNED AUTO_INCREMENT
        PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    leave_type_id BIGINT UNSIGNED NOT NULL,
    allocation_year SMALLINT UNSIGNED NOT NULL,
    entitlement_days DECIMAL(6,2) NOT NULL,
    carry_over_days DECIMAL(6,2) NOT NULL
        DEFAULT 0,
    notes VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT uq_leave_allocation_scope
        UNIQUE (
            company_id,
            employee_id,
            leave_type_id,
            allocation_year
        ),
    CONSTRAINT uq_leave_allocation_identity
        UNIQUE (company_id, allocation_id),
    CONSTRAINT ck_leave_allocation_year
        CHECK (
            allocation_year BETWEEN 2000 AND 2100
        ),
    CONSTRAINT ck_leave_allocation_entitlement
        CHECK (
            entitlement_days BETWEEN 0 AND 366
        ),
    CONSTRAINT ck_leave_allocation_carry
        CHECK (
            carry_over_days BETWEEN 0 AND 366
        ),
    CONSTRAINT fk_leave_allocation_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_leave_allocation_employee
        FOREIGN KEY (company_id, employee_id)
        REFERENCES hr_employees(
            company_id,
            employee_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_leave_allocation_type
        FOREIGN KEY (company_id, leave_type_id)
        REFERENCES hr_leave_types(
            company_id,
            leave_type_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_leave_allocation_creator
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_leave_allocation_updater
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_leave_allocation_employee_year (
        company_id,
        employee_id,
        allocation_year
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE hr_leave_balance_adjustments (
    adjustment_id BIGINT UNSIGNED AUTO_INCREMENT
        PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    allocation_id BIGINT UNSIGNED NOT NULL,
    adjustment_type VARCHAR(20) NOT NULL,
    adjustment_days DECIMAL(7,2) NOT NULL,
    effective_date DATE NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT ck_leave_adjustment_type
        CHECK (
            adjustment_type IN (
                'credit',
                'debit'
            )
        ),
    CONSTRAINT ck_leave_adjustment_days
        CHECK (
            adjustment_days <> 0
            AND adjustment_days
                BETWEEN -366 AND 366
            AND (
                (
                    adjustment_type = 'credit'
                    AND adjustment_days > 0
                )
                OR (
                    adjustment_type = 'debit'
                    AND adjustment_days < 0
                )
            )
        ),
    CONSTRAINT fk_leave_adjustment_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_leave_adjustment_allocation
        FOREIGN KEY (company_id, allocation_id)
        REFERENCES hr_leave_allocations(
            company_id,
            allocation_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_leave_adjustment_creator
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_leave_adjustment_ledger (
        company_id,
        allocation_id,
        effective_date,
        adjustment_id
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
