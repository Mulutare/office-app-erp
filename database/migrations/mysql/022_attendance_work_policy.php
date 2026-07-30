<?php

declare(strict_types=1);

return [
    'version' => '022',
    'description' =>
        'Add lunch, flexible start and net attendance policy snapshots',
    'preflight' => static function (
        \PDO $connection
    ): string {
        $statement = $connection->query(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND (
                    (
                        table_name =
                            \'workforce_calendar_days\'
                        AND column_name IN (
                            \'break_start_time\',
                            \'break_end_time\',
                            \'target_work_minutes\',
                            \'flex_start_minutes\'
                        )
                    )
                    OR (
                        table_name =
                            \'attendance_records\'
                        AND column_name IN (
                            \'gross_minutes\',
                            \'break_minutes\',
                            \'target_work_minutes\',
                            \'work_variance_minutes\'
                        )
                    )
               )'
        );
        $count = (int) $statement->fetchColumn();

        if ($count === 0) {
            return 'apply';
        }

        if ($count === 8) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 022 found a partial attendance work-policy schema.'
        );
    },
    'statements' => [
        <<<'SQL'
ALTER TABLE workforce_calendar_days
    ADD COLUMN break_start_time VARCHAR(5) NULL
        AFTER end_time,
    ADD COLUMN break_end_time VARCHAR(5) NULL
        AFTER break_start_time,
    ADD COLUMN target_work_minutes SMALLINT UNSIGNED
        NOT NULL DEFAULT 480 AFTER break_minutes,
    ADD COLUMN flex_start_minutes SMALLINT UNSIGNED
        NOT NULL DEFAULT 0 AFTER target_work_minutes
SQL,
        <<<'SQL'
ALTER TABLE workforce_calendar_days
    ADD CONSTRAINT ck_workforce_day_target
        CHECK (target_work_minutes BETWEEN 0 AND 960),
    ADD CONSTRAINT ck_workforce_day_flex
        CHECK (flex_start_minutes BETWEEN 0 AND 240)
SQL,
        <<<'SQL'
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
    END
SQL,
        <<<'SQL'
ALTER TABLE attendance_records
    ADD COLUMN gross_minutes INT UNSIGNED
        NOT NULL DEFAULT 0 AFTER work_minutes,
    ADD COLUMN break_minutes INT UNSIGNED
        NOT NULL DEFAULT 0 AFTER gross_minutes,
    ADD COLUMN target_work_minutes INT UNSIGNED
        NOT NULL DEFAULT 0 AFTER break_minutes,
    ADD COLUMN work_variance_minutes INT
        NOT NULL DEFAULT 0 AFTER target_work_minutes
SQL,
        <<<'SQL'
UPDATE attendance_records
SET gross_minutes = work_minutes
WHERE gross_minutes = 0
  AND work_minutes > 0
SQL,
    ],
];
