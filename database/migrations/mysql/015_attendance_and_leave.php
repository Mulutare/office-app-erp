<?php

declare(strict_types=1);

return [
    'version' => '015',
    'description' =>
        'Create tenant attendance and leave tables',
    'preflight' => static function (
        \PDO $connection
    ): string {
        $statement = $connection->query(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN (
                    \'attendance_records\',
                    \'hr_leave_types\',
                    \'hr_leave_requests\'
               )'
        );
        $tableCount = (int) $statement->fetchColumn();

        if ($tableCount === 0) {
            return 'apply';
        }

        if ($tableCount === 3) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 015 found a partial attendance and leave schema.'
        );
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE attendance_records (
    attendance_id BIGINT UNSIGNED AUTO_INCREMENT
        PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    check_in_at DATETIME NULL,
    check_out_at DATETIME NULL,
    attendance_status VARCHAR(20) NOT NULL
        DEFAULT 'present',
    work_minutes INT UNSIGNED NOT NULL
        DEFAULT 0,
    source VARCHAR(20) NOT NULL
        DEFAULT 'manual',
    notes VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT uq_attendance_employee_date
        UNIQUE (
            company_id,
            employee_id,
            attendance_date
        ),
    CONSTRAINT ck_attendance_status
        CHECK (
            attendance_status IN (
                'present',
                'late',
                'absent',
                'remote',
                'on_leave',
                'holiday'
            )
        ),
    CONSTRAINT ck_attendance_source
        CHECK (
            source IN (
                'manual',
                'import',
                'device',
                'system'
            )
        ),
    CONSTRAINT ck_attendance_time_order
        CHECK (
            check_out_at IS NULL
            OR (
                check_in_at IS NOT NULL
                AND check_out_at >= check_in_at
            )
        ),
    CONSTRAINT fk_attendance_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_employee
        FOREIGN KEY (company_id, employee_id)
        REFERENCES hr_employees(
            company_id,
            employee_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_creator
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_attendance_updater
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_attendance_company_date (
        company_id,
        attendance_date,
        attendance_status
    ),
    INDEX idx_attendance_employee_history (
        company_id,
        employee_id,
        attendance_date
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE hr_leave_types (
    leave_type_id BIGINT UNSIGNED AUTO_INCREMENT
        PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(100) NOT NULL,
    annual_entitlement DECIMAL(6,2) NOT NULL
        DEFAULT 0,
    requires_approval BOOLEAN NOT NULL
        DEFAULT TRUE,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,

    CONSTRAINT uq_leave_types_company_code
        UNIQUE (company_id, code),
    CONSTRAINT uq_leave_types_company_name
        UNIQUE (company_id, name),
    CONSTRAINT uq_leave_types_company_identity
        UNIQUE (company_id, leave_type_id),
    CONSTRAINT ck_leave_type_entitlement
        CHECK (
            annual_entitlement
                BETWEEN 0 AND 366
        ),
    CONSTRAINT fk_leave_type_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_leave_type_creator
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_leave_type_updater
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_leave_types_company_active (
        company_id,
        active,
        deleted_at
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE hr_leave_requests (
    leave_request_id BIGINT UNSIGNED AUTO_INCREMENT
        PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    leave_type_id BIGINT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    requested_days DECIMAL(6,2) NOT NULL,
    reason VARCHAR(500) NULL,
    request_status VARCHAR(20) NOT NULL
        DEFAULT 'pending',
    decision_note VARCHAR(500) NULL,
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT ck_leave_request_status
        CHECK (
            request_status IN (
                'pending',
                'approved',
                'rejected',
                'cancelled'
            )
        ),
    CONSTRAINT ck_leave_request_dates
        CHECK (end_date >= start_date),
    CONSTRAINT ck_leave_requested_days
        CHECK (
            requested_days > 0
            AND requested_days <= 366
        ),
    CONSTRAINT ck_leave_decision
        CHECK (
            (
                request_status = 'pending'
                AND decided_at IS NULL
            )
            OR (
                request_status = 'cancelled'
            )
            OR (
                request_status IN (
                    'approved',
                    'rejected'
                )
                AND decided_at IS NOT NULL
            )
        ),
    CONSTRAINT fk_leave_request_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_leave_request_employee
        FOREIGN KEY (company_id, employee_id)
        REFERENCES hr_employees(
            company_id,
            employee_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_leave_request_type
        FOREIGN KEY (company_id, leave_type_id)
        REFERENCES hr_leave_types(
            company_id,
            leave_type_id
        )
        ON DELETE RESTRICT,
    CONSTRAINT fk_leave_request_decider
        FOREIGN KEY (decided_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_leave_request_creator
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_leave_request_updater
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,

    INDEX idx_leave_requests_company_status (
        company_id,
        request_status,
        start_date,
        end_date
    ),
    INDEX idx_leave_requests_employee (
        company_id,
        employee_id,
        start_date
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
