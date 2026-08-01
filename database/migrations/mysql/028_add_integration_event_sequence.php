<?php

declare(strict_types=1);

return [
    'version' => '028',
    'description' => 'Add causal ordering to the integration outbox',
    'preflight' => static function (\PDO $connection): string {
        $statement = $connection->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'integration_outbox'
               AND column_name = 'outbox_sequence'"
        );
        $count = (int) $statement->fetchColumn();
        if ($count === 0) {
            return 'apply';
        }
        if ($count === 1) {
            return 'baseline';
        }
        throw new \RuntimeException(
            'Migration 028 found an invalid integration sequence schema.'
        );
    },
    'statements' => [
        <<<'SQL'
ALTER TABLE integration_outbox
    ADD COLUMN outbox_sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ADD CONSTRAINT uq_integration_outbox_sequence UNIQUE (outbox_sequence)
SQL,
    ],
];
