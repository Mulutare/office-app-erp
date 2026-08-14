<?php

declare(strict_types=1);

return static function (PDO $connection): string {
    $exists = static function (string $table) use ($connection): bool {
        $statement = $connection->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() === 1;
    };
    $count = static fn(string $sql): int => (int) $connection->query($sql)->fetchColumn();
    $effects = [
        1 => $exists('procurement_vendor_returns'),
        2 => $exists('procurement_vendor_return_lines'),
        3 => $count("SELECT COUNT(*) FROM permissions WHERE code='procurement.returns.post'") === 1,
        4 => $count("SELECT COUNT(*) FROM roles WHERE code IN ('procurement_requester','procurement_approver','purchasing_officer','warehouse_inventory_user')") === 4,
        5 => $count("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.permission_id=rp.permission_id JOIN roles r ON r.role_id=rp.role_id WHERE p.code='procurement.returns.post' AND r.code IN ('company_owner','system_administrator','warehouse_inventory_user')") === 3,
    ];
    $steps = $connection->query("SELECT statement_number FROM schema_migration_steps WHERE version='053' ORDER BY statement_number");
    $completed = array_map('intval', $steps->fetchAll(PDO::FETCH_COLUMN));
    if ($completed === []) {
        if (!in_array(true, $effects, true)) return 'apply';
        if (!in_array(false, $effects, true)) return 'baseline';
        throw new RuntimeException('Migration 053 found an untracked partial procurement recovery schema.');
    }
    if ($completed !== range(1, max($completed))) throw new RuntimeException('Migration 053 recovery steps are not a valid completed prefix.');
    foreach ($completed as $number) if (empty($effects[$number])) throw new RuntimeException('Migration 053 recovery metadata does not match the database structure.');
    foreach (array_keys($effects) as $number) if ($number > max($completed) && $effects[$number]) throw new RuntimeException('Migration 053 contains an unrecorded out-of-order schema effect.');
    return 'apply';
};
