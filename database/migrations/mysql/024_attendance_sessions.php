<?php

declare(strict_types=1);

return [
    'version' => '024',
    'description' =>
        'Create auditable multi-session attendance punches',
    'preflight' => static function (
        \PDO $connection
    ): string {
        $statement = $connection->query(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name =
                    \'attendance_sessions\''
        );

        if ((int) $statement->fetchColumn() === 0) {
            return 'apply';
        }

        $columns = $connection->query(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name =
                    \'attendance_sessions\'
               AND column_name IN (
                    \'session_id\',
                    \'attendance_id\',
                    \'company_id\',
                    \'employee_id\',
                    \'sequence_no\',
                    \'check_in_at\',
                    \'check_out_at\',
                    \'active\',
                    \'open_slot\',
                    \'source\',
                    \'invalidated_at\',
                    \'invalidated_by\',
                    \'created_by\',
                    \'updated_by\',
                    \'created_at\',
                    \'updated_at\'
               )'
        );

        if ((int) $columns->fetchColumn() === 16) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 024 found a partial attendance-session schema.'
        );
    },
    'statements' => [
        <<<'SQL'
ALTER TABLE attendance_records
    ADD CONSTRAINT uq_attendance_record_identity
        UNIQUE (
            company_id,
            employee_id,
            attendance_id
        )
SQL,
        <<<'SQL'
CREATE TABLE attendance_sessions (
    session_id BIGINT UNSIGNED AUTO_INCREMENT
        PRIMARY KEY,
    attendance_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    sequence_no SMALLINT UNSIGNED NOT NULL,
    check_in_at DATETIME NOT NULL,
    check_out_at DATETIME NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    open_slot TINYINT
        GENERATED ALWAYS AS (
            CASE
                WHEN active = TRUE
                    AND check_out_at IS NULL
                THEN 1
                ELSE NULL
            END
        ) STORED,
    source VARCHAR(20) NOT NULL
        DEFAULT 'self_service',
    invalidated_at DATETIME NULL,
    invalidated_by BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_attendance_session_sequence
        UNIQUE (attendance_id, sequence_no),
    CONSTRAINT uq_attendance_open_session
        UNIQUE (attendance_id, open_slot),
    CONSTRAINT ck_attendance_session_time
        CHECK (
            check_out_at IS NULL
            OR check_out_at >= check_in_at
        ),
    CONSTRAINT ck_attendance_session_active
        CHECK (active IN (TRUE, FALSE)),
    CONSTRAINT ck_attendance_session_source
        CHECK (
            source IN (
                'manual',
                'import',
                'device',
                'system',
                'self_service'
            )
        ),
    CONSTRAINT fk_attendance_session_record
        FOREIGN KEY (
            company_id,
            employee_id,
            attendance_id
        )
        REFERENCES attendance_records (
            company_id,
            employee_id,
            attendance_id
        )
        ON DELETE CASCADE,
    CONSTRAINT fk_attendance_session_invalidated
        FOREIGN KEY (invalidated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_attendance_session_creator
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_attendance_session_updater
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    INDEX idx_attendance_session_employee (
        company_id,
        employee_id,
        check_in_at
    ),
    INDEX idx_attendance_session_record_active (
        attendance_id,
        active,
        sequence_no
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
INSERT INTO attendance_sessions (
    attendance_id,
    company_id,
    employee_id,
    sequence_no,
    check_in_at,
    check_out_at,
    active,
    source,
    created_by,
    updated_by
)
SELECT
    attendance_id,
    company_id,
    employee_id,
    1,
    check_in_at,
    check_out_at,
    TRUE,
    source,
    created_by,
    updated_by
FROM attendance_records
WHERE check_in_at IS NOT NULL
SQL,
    ],
];
