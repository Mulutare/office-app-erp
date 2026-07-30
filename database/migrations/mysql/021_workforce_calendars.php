<?php

declare(strict_types=1);

return [
    'version' => '021',
    'description' =>
        'Create workforce calendars, schedules and attendance notification outbox',
    'preflight' => static function (
        \PDO $connection
    ): string {
        $tables = [
            'workforce_calendars',
            'workforce_calendar_days',
            'workforce_holidays',
            'employee_work_schedules',
            'attendance_notifications',
        ];
        $placeholders = implode(
            ', ',
            array_fill(0, count($tables), '?')
        );
        $statement = $connection->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN (' . $placeholders . ')'
        );
        $statement->execute($tables);
        $count = (int) $statement->fetchColumn();

        if ($count === 0) {
            return 'apply';
        }

        if ($count === count($tables)) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 021 found a partial workforce-calendar schema.'
        );
    },
    'statements' => [
        <<<'SQL'
CREATE TABLE workforce_calendars (
    calendar_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    timezone VARCHAR(80) NOT NULL,
    country_code CHAR(2) NULL,
    subdivision_code VARCHAR(16) NULL,
    week_start SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_workforce_calendar_code
        UNIQUE (company_id, code),
    CONSTRAINT uq_workforce_calendar_scope
        UNIQUE (company_id, calendar_id),
    CONSTRAINT ck_workforce_calendar_week_start
        CHECK (week_start BETWEEN 1 AND 7),
    CONSTRAINT fk_workforce_calendar_company
        FOREIGN KEY (company_id)
        REFERENCES companies(company_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_workforce_calendar_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_workforce_calendar_updated_by
        FOREIGN KEY (updated_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE workforce_calendar_days (
    calendar_id BIGINT UNSIGNED NOT NULL,
    iso_weekday SMALLINT UNSIGNED NOT NULL,
    working_day BOOLEAN NOT NULL DEFAULT TRUE,
    start_time VARCHAR(5) NULL,
    end_time VARCHAR(5) NULL,
    break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (calendar_id, iso_weekday),
    CONSTRAINT ck_workforce_day_weekday
        CHECK (iso_weekday BETWEEN 1 AND 7),
    CONSTRAINT ck_workforce_day_break
        CHECK (break_minutes BETWEEN 0 AND 480),
    CONSTRAINT fk_workforce_day_calendar
        FOREIGN KEY (calendar_id)
        REFERENCES workforce_calendars(calendar_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE workforce_holidays (
    holiday_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    calendar_id BIGINT UNSIGNED NOT NULL,
    holiday_date DATE NOT NULL,
    name VARCHAR(150) NOT NULL,
    holiday_type VARCHAR(20) NOT NULL DEFAULT 'public',
    day_portion VARCHAR(10) NOT NULL DEFAULT 'full',
    observed BOOLEAN NOT NULL DEFAULT FALSE,
    description VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_workforce_holiday
        UNIQUE (calendar_id, holiday_date, name),
    CONSTRAINT ck_workforce_holiday_type
        CHECK (holiday_type IN ('public', 'company')),
    CONSTRAINT ck_workforce_holiday_portion
        CHECK (day_portion IN ('full', 'am', 'pm')),
    CONSTRAINT fk_workforce_holiday_calendar
        FOREIGN KEY (company_id, calendar_id)
        REFERENCES workforce_calendars(company_id, calendar_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_workforce_holiday_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE employee_work_schedules (
    schedule_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    calendar_id BIGINT UNSIGNED NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_employee_work_schedule
        UNIQUE (
            company_id,
            employee_id,
            calendar_id,
            effective_from
        ),
    CONSTRAINT ck_employee_schedule_dates
        CHECK (
            effective_to IS NULL
            OR effective_to >= effective_from
        ),
    CONSTRAINT fk_employee_schedule_employee
        FOREIGN KEY (company_id, employee_id)
        REFERENCES hr_employees(company_id, employee_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_employee_schedule_calendar
        FOREIGN KEY (company_id, calendar_id)
        REFERENCES workforce_calendars(company_id, calendar_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_employee_schedule_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    INDEX idx_employee_schedule_effective (
        company_id,
        employee_id,
        active,
        effective_from,
        effective_to
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL,
        <<<'SQL'
CREATE TABLE attendance_notifications (
    notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    notification_type VARCHAR(30) NOT NULL,
    title VARCHAR(160) NOT NULL,
    body VARCHAR(500) NOT NULL,
    scheduled_for DATETIME NOT NULL,
    local_date DATE NOT NULL,
    channel VARCHAR(20) NOT NULL DEFAULT 'in_app',
    status VARCHAR(20) NOT NULL DEFAULT 'unread',
    dedupe_key VARCHAR(190) NOT NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_attendance_notification_dedupe
        UNIQUE (company_id, user_id, dedupe_key),
    CONSTRAINT ck_attendance_notification_type
        CHECK (
            notification_type IN (
                'check_in',
                'check_out'
            )
        ),
    CONSTRAINT ck_attendance_notification_channel
        CHECK (channel = 'in_app'),
    CONSTRAINT ck_attendance_notification_status
        CHECK (status IN ('unread', 'read')),
    CONSTRAINT fk_attendance_notification_user
        FOREIGN KEY (company_id, user_id)
        REFERENCES company_users(company_id, user_id)
        ON DELETE CASCADE,
    INDEX idx_attendance_notification_inbox (
        company_id,
        user_id,
        status,
        scheduled_for
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
SQL,
    ],
];
