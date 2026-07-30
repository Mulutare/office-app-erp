<?php

declare(strict_types=1);

return [
    'version' => '020',
    'description' =>
        'Create user-owned attendance reminder preferences',
    'preflight' => static function (
        \PDO $connection
    ): string {
        $tableStatement = $connection->query(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name =
                    \'attendance_user_reminders\''
        );
        $tableCount = (int) $tableStatement
            ->fetchColumn();
        $columnStatement = $connection->query(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name =
                    \'attendance_user_reminders\'
               AND column_name IN (
                    \'reminder_id\',
                    \'company_id\',
                    \'user_id\',
                    \'timezone\',
                    \'workday_mask\',
                    \'check_in_enabled\',
                    \'check_in_time\',
                    \'check_out_enabled\',
                    \'check_out_time\',
                    \'reminder_lead_minutes\',
                    \'browser_notifications_enabled\',
                    \'created_at\',
                    \'updated_at\'
               )'
        );
        $columnCount = (int) $columnStatement
            ->fetchColumn();

        if ($tableCount === 0 && $columnCount === 0) {
            return 'apply';
        }

        if ($tableCount === 1 && $columnCount === 13) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 020 found a partial attendance-reminder schema.'
        );
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE attendance_user_reminders (
    reminder_id BIGINT UNSIGNED AUTO_INCREMENT
        PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    timezone VARCHAR(80) NOT NULL,
    workday_mask SMALLINT UNSIGNED NOT NULL
        DEFAULT 31,
    check_in_enabled BOOLEAN NOT NULL
        DEFAULT FALSE,
    check_in_time VARCHAR(5) NOT NULL
        DEFAULT '08:30',
    check_out_enabled BOOLEAN NOT NULL
        DEFAULT FALSE,
    check_out_time VARCHAR(5) NOT NULL
        DEFAULT '17:30',
    reminder_lead_minutes SMALLINT UNSIGNED
        NOT NULL DEFAULT 10,
    browser_notifications_enabled BOOLEAN
        NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_attendance_user_reminder
        UNIQUE (company_id, user_id),
    CONSTRAINT ck_att_reminder_workdays
        CHECK (workday_mask BETWEEN 1 AND 127),
    CONSTRAINT ck_att_reminder_check_in
        CHECK (check_in_enabled IN (FALSE, TRUE)),
    CONSTRAINT ck_att_reminder_check_out
        CHECK (check_out_enabled IN (FALSE, TRUE)),
    CONSTRAINT ck_att_reminder_lead
        CHECK (
            reminder_lead_minutes IN (
                0,
                5,
                10,
                15,
                30,
                60
            )
        ),
    CONSTRAINT ck_att_reminder_browser
        CHECK (
            browser_notifications_enabled
                IN (FALSE, TRUE)
        ),
    CONSTRAINT fk_att_reminder_company_user
        FOREIGN KEY (company_id, user_id)
        REFERENCES company_users (
            company_id,
            user_id
        )
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
