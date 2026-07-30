<?php

declare(strict_types=1);

return [
    'version' => '016',
    'description' =>
        'Add tenant reporting managers to company memberships',
    'preflight' => static function (
        \PDO $connection
    ): string {
        $columnStatement = $connection->query(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = \'company_users\'
               AND column_name = \'manager_user_id\''
        );
        $constraintStatement = $connection->query(
            'SELECT COUNT(*)
             FROM information_schema.referential_constraints
             WHERE constraint_schema = DATABASE()
               AND table_name = \'company_users\'
               AND constraint_name =
                    \'fk_company_users_manager\''
        );
        $indexStatement = $connection->query(
            'SELECT COUNT(DISTINCT index_name)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = \'company_users\'
               AND index_name =
                    \'idx_company_users_manager\''
        );
        $columnCount = (int) $columnStatement
            ->fetchColumn();
        $constraintCount = (int) $constraintStatement
            ->fetchColumn();
        $indexCount = (int) $indexStatement
            ->fetchColumn();

        if (
            $columnCount === 0
            && $constraintCount === 0
            && $indexCount === 0
        ) {
            return 'apply';
        }

        if (
            $columnCount === 1
            && $constraintCount === 1
            && $indexCount === 1
        ) {
            return 'baseline';
        }

        throw new \RuntimeException(
            'Migration 016 found a partial company manager schema.'
        );
    },
    'statements' => [
        <<<'SQL'
ALTER TABLE company_users
    ADD COLUMN manager_user_id BIGINT UNSIGNED NULL
        AFTER user_id,
    ADD CONSTRAINT fk_company_users_manager
        FOREIGN KEY (manager_user_id)
        REFERENCES users(user_id)
        ON DELETE SET NULL,
    ADD INDEX idx_company_users_manager (
        company_id,
        manager_user_id,
        active
    )
SQL,
    ],
];
