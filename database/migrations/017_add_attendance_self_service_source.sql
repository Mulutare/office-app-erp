ALTER TABLE attendance_records
    DROP CONSTRAINT ck_attendance_source;

ALTER TABLE attendance_records
    ADD CONSTRAINT ck_attendance_source
        CHECK (
            source IN (
                'manual',
                'import',
                'device',
                'system',
                'self_service'
            )
        );
