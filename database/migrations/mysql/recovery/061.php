<?php

declare(strict_types=1);

return static function (PDO $connection): string {
    $steps = array_map(
        'intval',
        $connection
            ->query(
                "SELECT statement_number
                 FROM schema_migration_steps
                 WHERE version = '061'
                 ORDER BY statement_number"
            )
            ->fetchAll(PDO::FETCH_COLUMN)
    );

    if (
        $steps !== []
        && $steps !== range(1, max($steps))
    ) {
        throw new RuntimeException(
            'Migration 061 recovery steps are not a valid completed prefix.'
        );
    }

    $tableExists = (int) $connection
        ->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name =
                   'authenticated_user_sessions'"
        )
        ->fetchColumn() === 1;

    if (!$tableExists) {
        if ($steps !== []) {
            throw new RuntimeException(
                'Migration 061 metadata exists but its table is missing.'
            );
        }

        return 'apply';
    }

    $requiredColumns = [
        'authenticated_user_session_id',
        'company_id',
        'user_id',
        'session_hash',
        'signed_in_at',
        'last_activity_at',
        'expires_at',
        'revoked_at',
        'ip_address',
        'user_agent',
        'created_at',
        'updated_at',
    ];

    $columnStatement = $connection->prepare(
        "SELECT COUNT(DISTINCT column_name)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name =
               'authenticated_user_sessions'
           AND column_name IN (
               'authenticated_user_session_id',
               'company_id',
               'user_id',
               'session_hash',
               'signed_in_at',
               'last_activity_at',
               'expires_at',
               'revoked_at',
               'ip_address',
               'user_agent',
               'created_at',
               'updated_at'
           )"
    );

    $columnStatement->execute();

    $columnsComplete =
        (int) $columnStatement->fetchColumn()
        === count($requiredColumns);

    $indexStatement = $connection->query(
        "SELECT COUNT(DISTINCT index_name)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name =
               'authenticated_user_sessions'
           AND index_name IN (
               'uq_authenticated_session_hash',
               'idx_authenticated_session_user',
               'idx_authenticated_session_active',
               'idx_authenticated_session_expiry'
           )"
    );

    $indexesComplete =
        (int) $indexStatement->fetchColumn() === 4;

    $foreignKeyStatement = $connection->query(
        "SELECT COUNT(*)
         FROM information_schema.referential_constraints
         WHERE constraint_schema = DATABASE()
           AND table_name =
               'authenticated_user_sessions'
           AND constraint_name IN (
               'fk_authenticated_session_company',
               'fk_authenticated_session_user'
           )"
    );

    $foreignKeysComplete =
        (int) $foreignKeyStatement->fetchColumn() === 2;

    $complete =
        $columnsComplete
        && $indexesComplete
        && $foreignKeysComplete;

    if (!$complete) {
        throw new RuntimeException(
            'Migration 061 found an incomplete authenticated session schema.'
        );
    }

    if ($steps !== [] && max($steps) >= 1) {
        return 'baseline';
    }

    return 'baseline';
};
