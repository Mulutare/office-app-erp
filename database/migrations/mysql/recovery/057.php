<?php

declare(strict_types=1);

return static function (\PDO $connection): string {
    $completed = array_map(
        'intval',
        $connection->query(
            "SELECT statement_number FROM schema_migration_steps WHERE version='057' ORDER BY statement_number"
        )->fetchAll(\PDO::FETCH_COLUMN)
    );
    if ($completed !== [] && $completed !== [1]) {
        throw new \RuntimeException('Migration 057 recovery steps are not a valid completed prefix.');
    }
    $exists = (int) $connection->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='inventory_valuation_layers'"
    )->fetchColumn() === 1;
    if ($completed === [1] && !$exists) {
        throw new \RuntimeException('Migration 057 recovery metadata does not match the database state.');
    }
    return $completed === [] && $exists ? 'baseline' : 'apply';
};
