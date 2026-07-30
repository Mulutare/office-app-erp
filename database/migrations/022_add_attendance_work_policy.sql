ALTER TABLE workforce_calendar_days
    ADD COLUMN break_start_time VARCHAR(5) NULL
        AFTER end_time,
    ADD COLUMN break_end_time VARCHAR(5) NULL
        AFTER break_start_time,
    ADD COLUMN target_work_minutes SMALLINT UNSIGNED
        NOT NULL DEFAULT 480 AFTER break_minutes,
    ADD COLUMN flex_start_minutes SMALLINT UNSIGNED
        NOT NULL DEFAULT 0 AFTER target_work_minutes;

ALTER TABLE workforce_calendar_days
    ADD CONSTRAINT ck_workforce_day_target
        CHECK (target_work_minutes BETWEEN 0 AND 960),
    ADD CONSTRAINT ck_workforce_day_flex
        CHECK (flex_start_minutes BETWEEN 0 AND 240);

UPDATE workforce_calendar_days
SET break_start_time = CASE
        WHEN working_day = TRUE
            AND break_minutes = 60
            AND start_time = '08:30'
            AND end_time = '17:30'
        THEN '12:30'
        ELSE NULL
    END,
    break_end_time = CASE
        WHEN working_day = TRUE
            AND break_minutes = 60
            AND start_time = '08:30'
            AND end_time = '17:30'
        THEN '13:30'
        ELSE NULL
    END,
    target_work_minutes = CASE
        WHEN working_day = TRUE THEN 480
        ELSE 0
    END,
    flex_start_minutes = CASE
        WHEN working_day = TRUE THEN 30
        ELSE 0
    END;

ALTER TABLE attendance_records
    ADD COLUMN gross_minutes INT UNSIGNED
        NOT NULL DEFAULT 0 AFTER work_minutes,
    ADD COLUMN break_minutes INT UNSIGNED
        NOT NULL DEFAULT 0 AFTER gross_minutes,
    ADD COLUMN target_work_minutes INT UNSIGNED
        NOT NULL DEFAULT 0 AFTER break_minutes,
    ADD COLUMN work_variance_minutes INT
        NOT NULL DEFAULT 0 AFTER target_work_minutes;

UPDATE attendance_records
SET gross_minutes = work_minutes
WHERE gross_minutes = 0
  AND work_minutes > 0;
