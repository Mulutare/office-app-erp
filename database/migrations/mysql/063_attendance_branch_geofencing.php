<?php

declare(strict_types=1);

return [
    'version' => '063',
    'description' =>
        'Add HR-managed branch attendance geofences and location audit evidence',

    'preflight' => static function (
        \PDO $connection
    ): string {
        $branchColumns = $connection->query(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = \'organization_branches\'
               AND column_name IN (
                    \'attendance_geofence_enabled\',
                    \'attendance_latitude\',
                    \'attendance_longitude\',
                    \'attendance_radius_meters\'
               )'
        );

        $scanColumns = $connection->query(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = \'attendance_scan_events\'
               AND column_name IN (
                    \'geofence_enforced\',
                    \'geofence_branch_id\',
                    \'geofence_branch_name_snapshot\',
                    \'geofence_latitude_snapshot\',
                    \'geofence_longitude_snapshot\',
                    \'geofence_radius_meters_snapshot\',
                    \'location_latitude\',
                    \'location_longitude\',
                    \'location_accuracy_meters\',
                    \'geofence_distance_meters\'
               )'
        );

        $branchCount = (int) $branchColumns->fetchColumn();
        $scanCount = (int) $scanColumns->fetchColumn();

        if ($branchCount === 0 && $scanCount === 0) {
            return 'apply';
        }

        if ($branchCount === 4 && $scanCount === 10) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 063 found a partial attendance-geofence schema.'
        );
    },

    'statements' => [
        <<<'SQL'
ALTER TABLE organization_branches
    ADD COLUMN attendance_geofence_enabled
        BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN attendance_latitude
        DECIMAL(10,7) NULL,
    ADD COLUMN attendance_longitude
        DECIMAL(10,7) NULL,
    ADD COLUMN attendance_radius_meters
        INT UNSIGNED NULL,

    ADD CONSTRAINT ck_org_branch_attendance_latitude
        CHECK (
            attendance_latitude IS NULL
            OR attendance_latitude BETWEEN -90 AND 90
        ),

    ADD CONSTRAINT ck_org_branch_attendance_longitude
        CHECK (
            attendance_longitude IS NULL
            OR attendance_longitude BETWEEN -180 AND 180
        ),

    ADD CONSTRAINT ck_org_branch_attendance_radius
        CHECK (
            attendance_radius_meters IS NULL
            OR attendance_radius_meters BETWEEN 10 AND 50000
        ),

    ADD CONSTRAINT ck_org_branch_attendance_geofence
        CHECK (
            attendance_geofence_enabled = FALSE
            OR (
                attendance_latitude IS NOT NULL
                AND attendance_longitude IS NOT NULL
                AND attendance_radius_meters IS NOT NULL
            )
        )
SQL,
        <<<'SQL'
ALTER TABLE attendance_scan_events
    ADD COLUMN geofence_enforced
        BOOLEAN NOT NULL DEFAULT FALSE,

    ADD COLUMN geofence_branch_id
        BIGINT UNSIGNED NULL,

    ADD COLUMN geofence_branch_name_snapshot
        VARCHAR(120) NULL,

    ADD COLUMN geofence_latitude_snapshot
        DECIMAL(10,7) NULL,

    ADD COLUMN geofence_longitude_snapshot
        DECIMAL(10,7) NULL,

    ADD COLUMN geofence_radius_meters_snapshot
        INT UNSIGNED NULL,

    ADD COLUMN location_latitude
        DECIMAL(10,7) NULL,

    ADD COLUMN location_longitude
        DECIMAL(10,7) NULL,

    ADD COLUMN location_accuracy_meters
        DECIMAL(10,2) NULL,

    ADD COLUMN geofence_distance_meters
        DECIMAL(10,2) NULL,

    ADD CONSTRAINT ck_attendance_scan_location_latitude
        CHECK (
            location_latitude IS NULL
            OR location_latitude BETWEEN -90 AND 90
        ),

    ADD CONSTRAINT ck_attendance_scan_location_longitude
        CHECK (
            location_longitude IS NULL
            OR location_longitude BETWEEN -180 AND 180
        ),

    ADD CONSTRAINT ck_attendance_scan_geofence_latitude
        CHECK (
            geofence_latitude_snapshot IS NULL
            OR geofence_latitude_snapshot BETWEEN -90 AND 90
        ),

    ADD CONSTRAINT ck_attendance_scan_geofence_longitude
        CHECK (
            geofence_longitude_snapshot IS NULL
            OR geofence_longitude_snapshot BETWEEN -180 AND 180
        ),

    ADD CONSTRAINT ck_attendance_scan_accuracy
        CHECK (
            location_accuracy_meters IS NULL
            OR location_accuracy_meters >= 0
        ),

    ADD CONSTRAINT ck_attendance_scan_distance
        CHECK (
            geofence_distance_meters IS NULL
            OR geofence_distance_meters >= 0
        ),

    ADD CONSTRAINT fk_attendance_scan_geofence_branch
        FOREIGN KEY (
            company_id,
            geofence_branch_id
        )
        REFERENCES organization_branches (
            company_id,
            branch_id
        )
        ON DELETE RESTRICT
SQL,
    ],
];