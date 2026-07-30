<?php

declare(strict_types=1);

return [
    'version' => '220',
    'description' =>
        'Add lunch, flexible start and net attendance policy snapshots',
    'statements' => [
        <<<'SQL'
ALTER TABLE workforce_calendar_days ADD (
    break_start_time VARCHAR2(5 CHAR),
    break_end_time VARCHAR2(5 CHAR),
    target_work_minutes NUMBER(4) DEFAULT 480 NOT NULL,
    flex_start_minutes NUMBER(3) DEFAULT 0 NOT NULL
)
SQL,
        <<<'SQL'
ALTER TABLE workforce_calendar_days
    ADD CONSTRAINT ck_workforce_day_target
        CHECK (target_work_minutes BETWEEN 0 AND 960)
SQL,
        <<<'SQL'
ALTER TABLE workforce_calendar_days
    ADD CONSTRAINT ck_workforce_day_flex
        CHECK (flex_start_minutes BETWEEN 0 AND 240)
SQL,
        <<<'SQL'
UPDATE workforce_calendar_days
SET break_start_time = CASE
        WHEN working_day = 1
            AND break_minutes = 60
            AND start_time = '08:30'
            AND end_time = '17:30'
        THEN '12:30'
        ELSE NULL
    END,
    break_end_time = CASE
        WHEN working_day = 1
            AND break_minutes = 60
            AND start_time = '08:30'
            AND end_time = '17:30'
        THEN '13:30'
        ELSE NULL
    END,
    target_work_minutes = CASE
        WHEN working_day = 1 THEN 480
        ELSE 0
    END,
    flex_start_minutes = CASE
        WHEN working_day = 1 THEN 30
        ELSE 0
    END
SQL,
        <<<'SQL'
ALTER TABLE attendance_records ADD (
    gross_minutes NUMBER(10) DEFAULT 0 NOT NULL,
    break_minutes NUMBER(10) DEFAULT 0 NOT NULL,
    target_work_minutes NUMBER(10) DEFAULT 0 NOT NULL,
    work_variance_minutes NUMBER(10) DEFAULT 0 NOT NULL
)
SQL,
        <<<'SQL'
UPDATE attendance_records
SET gross_minutes = work_minutes
WHERE gross_minutes = 0
  AND work_minutes > 0
SQL,
    ],
];
