<?php

declare(strict_types=1);

return static function (PDO $connection): string {
    $tableExists = static function (string $table) use ($connection): bool {
        $statement = $connection->prepare(
            'SELECT COUNT(*) FROM information_schema.tables'
            . ' WHERE table_schema=DATABASE() AND table_name=:table'
        );
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() === 1;
    };
    $count = static fn (string $sql): int =>
        (int) $connection->query($sql)->fetchColumn();

    $effects = [
        1 => $count("SELECT COUNT(*) FROM erp_modules WHERE code='assets' AND active=TRUE") === 1,
        2 => $count("SELECT COUNT(*) FROM permissions WHERE code LIKE 'assets.%' AND active=TRUE") === 7,
        3 => $count("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.role_id=rp.role_id JOIN permissions p ON p.permission_id=rp.permission_id WHERE r.code IN('system_administrator','company_owner') AND p.code LIKE 'assets.%'") === 14,
        4 => $tableExists('asset_categories'),
        5 => $tableExists('fixed_assets'),
        6 => $tableExists('asset_depreciation_schedule'),
        7 => $tableExists('asset_transfers'),
        8 => $tableExists('asset_maintenance_records'),
        9 => $tableExists('asset_disposals'),
        10 => $tableExists('asset_history'),
    ];

    $steps = $connection->query(
        "SELECT statement_number FROM schema_migration_steps"
        . " WHERE version='046' ORDER BY statement_number"
    );
    $completed = array_map('intval', $steps->fetchAll(PDO::FETCH_COLUMN));

    if ($completed === []) {
        if (!in_array(true, $effects, true)) {
            return 'apply';
        }
        if (!in_array(false, $effects, true)) {
            return 'baseline';
        }
        throw new RuntimeException(
            'Migration 046 found an untracked partial Fixed Assets schema.'
        );
    }

    if ($completed !== range(1, max($completed))) {
        throw new RuntimeException(
            'Migration 046 recovery steps are not a valid completed prefix.'
        );
    }
    foreach ($completed as $number) {
        if (empty($effects[$number])) {
            throw new RuntimeException(
                'Migration 046 recovery metadata does not match the database structure.'
            );
        }
    }
    foreach (array_keys($effects) as $number) {
        if ($number > max($completed) && $effects[$number]) {
            throw new RuntimeException(
                'Migration 046 contains an unrecorded out-of-order schema effect.'
            );
        }
    }

    return 'apply';
};
