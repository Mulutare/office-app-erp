SET NAMES utf8mb4;

ALTER TABLE workforce_calendar_days
    ADD COLUMN scan_open_before_minutes
        SMALLINT UNSIGNED NOT NULL DEFAULT 120,
    ADD COLUMN scan_close_after_minutes
        SMALLINT UNSIGNED NOT NULL DEFAULT 240,
    ADD CONSTRAINT ck_workforce_day_scan_open
        CHECK (scan_open_before_minutes BETWEEN 0 AND 720),
    ADD CONSTRAINT ck_workforce_day_scan_close
        CHECK (scan_close_after_minutes BETWEEN 0 AND 720);

ALTER TABLE attendance_records
    ADD COLUMN schedule_calendar_id
        BIGINT UNSIGNED NULL,
    ADD COLUMN schedule_timezone
        VARCHAR(64) NULL,
    ADD COLUMN scheduled_start_at
        DATETIME NULL,
    ADD COLUMN scheduled_end_at
        DATETIME NULL,
    ADD COLUMN scan_window_start_at
        DATETIME NULL,
    ADD COLUMN scan_window_end_at
        DATETIME NULL,
    ADD COLUMN department_id_snapshot
        BIGINT UNSIGNED NULL,
    ADD COLUMN department_name_snapshot
        VARCHAR(120) NULL,
    ADD COLUMN late_minutes
        INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN early_departure_minutes
        INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN missing_clock_out
        BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN schedule_snapshot_json
        LONGTEXT NULL,
    ADD CONSTRAINT ck_attendance_missing_clock_out
        CHECK (missing_clock_out IN (TRUE, FALSE)),
    ADD CONSTRAINT fk_attendance_schedule_calendar
        FOREIGN KEY (schedule_calendar_id)
        REFERENCES workforce_calendars(calendar_id)
        ON DELETE SET NULL;

CREATE TABLE attendance_scan_events (
    event_id BIGINT UNSIGNED AUTO_INCREMENT
        PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    attendance_id BIGINT UNSIGNED NULL,
    attendance_date DATE NOT NULL,
    request_key VARCHAR(64) NOT NULL,
    scanned_at DATETIME NOT NULL,
    timezone VARCHAR(64) NOT NULL,
    event_type VARCHAR(24) NOT NULL,
    source VARCHAR(20) NOT NULL,
    device_reference VARCHAR(120) NULL,
    processing_result VARCHAR(20) NOT NULL,
    result_reason VARCHAR(190) NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL
        DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_attendance_scan_request
        UNIQUE (company_id, employee_id, request_key),
    CONSTRAINT ck_attendance_scan_event_type
        CHECK (
            event_type IN (
                'clock_in',
                'clock_out',
                'clock_out_update',
                'rejected'
            )
        ),
    CONSTRAINT ck_attendance_scan_source
        CHECK (
            source IN (
                'self_service',
                'device',
                'import',
                'manual',
                'system'
            )
        ),
    CONSTRAINT ck_attendance_scan_result
        CHECK (
            processing_result IN (
                'accepted',
                'rejected'
            )
        ),
    CONSTRAINT fk_attendance_scan_employee
        FOREIGN KEY (company_id, employee_id)
        REFERENCES hr_employees(company_id, employee_id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_scan_record
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
        ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_scan_actor
        FOREIGN KEY (actor_user_id)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    INDEX idx_attendance_scan_record (
        company_id,
        attendance_id,
        scanned_at
    ),
    INDEX idx_attendance_scan_employee_date (
        company_id,
        employee_id,
        attendance_date,
        scanned_at
    ),
    INDEX idx_attendance_scan_result (
        company_id,
        attendance_date,
        processing_result,
        event_type
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
