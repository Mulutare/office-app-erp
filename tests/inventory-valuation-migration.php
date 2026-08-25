<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

$passed = 0;
$failed = [];
$check = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    fwrite($condition ? STDOUT : STDERR, ($condition ? 'PASS ' : 'FAIL ') . $message . PHP_EOL);
    $condition ? $passed++ : $failed[] = $message;
};
$definition = require __DIR__ . '/../database/migrations/mysql/057_inventory_valuation_accounting.php';
$sql = implode("\n", $definition['statements'] ?? []);
$check(($definition['version'] ?? null) === '057' && count($definition['statements'] ?? []) === 1, 'Migration 057 is one additive forward step');
$check(str_contains($sql, 'inventory_valuation_layers') && str_contains($sql, 'uq_inventory_valuation_key') && str_contains($sql, 'fk_inventory_valuation_movement'), 'Valuation migration enforces idempotency and movement traceability');
$columns = db()->query("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='inventory_valuation_layers'")->fetchAll(PDO::FETCH_COLUMN);
$check(count($columns) === 21 && in_array('journal_batch_id', $columns, true) && in_array('reversal_of_layer_id', $columns, true), 'Fresh migration creates the complete valuation-layer schema');
$recovery = require __DIR__ . '/../database/migrations/mysql/recovery/057.php';
$check($recovery(db()) === 'baseline', 'Existing complete valuation schema is safely baselined without step metadata');
db()->beginTransaction();
db()->prepare("INSERT INTO schema_migration_steps(version,statement_number,migration_checksum,statement_checksum) VALUES('057',1,:migration,:statement)")->execute(['migration'=>str_repeat('a',64),'statement'=>str_repeat('b',64)]);
$completedRecovery = $recovery(db());
db()->rollBack();
$check($completedRecovery === 'apply', 'Completed-step recovery validates the existing migration-057 table');
$recoverySource = (string) file_get_contents(__DIR__ . '/../database/migrations/mysql/recovery/057.php');
$check(str_contains($recoverySource, "\$completed === [] && \$exists ? 'baseline' : 'apply'"), 'Recovery baselines an existing complete table only when no step metadata exists');
fwrite(STDOUT, sprintf("Inventory valuation migration: %d passed, %d failed.%s", $passed, count($failed), PHP_EOL));
exit($failed === [] ? 0 : 1);
