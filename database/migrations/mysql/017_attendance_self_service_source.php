<?php

declare(strict_types=1);

return [
    'version' => '017',
    'description' =>
        'Allow employee self-service attendance records',
    'preflight' => static function (
        \PDO $connection
    ): string {
        $statement = $connection->query(
            'SELECT check_clause
             FROM information_schema.check_constraints
             WHERE constraint_schema = DATABASE()
               AND constraint_name =
                    \'ck_attendance_source\''
        );
        $clause = $statement->fetchColumn();

        if (!is_string($clause)) {
            throw new \RuntimeException(
                'Migration 017 requires the attendance source constraint.'
            );
        }

        return stripos(
            $clause,
            'self_service'
        ) !== false
            ? 'baseline'
            : 'apply';
    },
    'statements' => [
        <<<'SQL'
ALTER TABLE attendance_records
    DROP CONSTRAINT ck_attendance_source
SQL,
        <<<'SQL'
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
        )
SQL,
    ],
];
