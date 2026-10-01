<?php

declare(strict_types=1);

require __DIR__ . '/rehearsal-baseline-audit.php';

$checks = 0;
$failures = 0;
$evidence = ['result' => 'FAIL', 'fixture_candidates' => []];
$ownedTables = [];
$ownedViews = [];
$pdo = null;
$assert = static function (bool $condition, string $description) use (&$checks, &$failures): void {
    ++$checks;
    if (!$condition) {
        ++$failures;
        throw new RuntimeException($description);
    }
    echo 'PASS: ', $description, PHP_EOL;
};
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';

try {
    // This fixture may only run on a separately initialized, empty disposable server.
    // It must never run on the restored production clone or normal development DB.
    $assert(
        getenv('OFFICEAPP_REHEARSAL_FIXTURE') === '1'
        && getenv('OFFICEAPP_REHEARSAL_ISOLATED') === '1'
        && getenv('DB_HOST') === 'db'
        && getenv('DB_DATABASE') === 'passiontech_officeapp'
        && getenv('DB_USERNAME') === 'root'
        && (string) getenv('DB_PASSWORD') === ''
        && in_array((string) getenv('DB_PORT'), ['', '3306'], true)
        && preg_match('/^officeapp-rehearsal-[a-f0-9]{32}-php$/', (string) getenv('OFFICEAPP_REHEARSAL_CONTAINER')) === 1,
        'Explicit empty-server fixture isolation environment is required'
    );
    $pdo = new PDO(
        'mysql:host=db;port=3306;dbname=passiontech_officeapp;charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $identity = $pdo->query('SELECT DATABASE() AS database_name, VERSION() AS version, @@version_comment AS version_comment')->fetch();
    $evidence['identity'] = $identity + ['container' => getenv('OFFICEAPP_REHEARSAL_CONTAINER')];
    echo 'Fixture database: ', $identity['database_name'], '; container: ', getenv('OFFICEAPP_REHEARSAL_CONTAINER'), '; server: ', $identity['version'], PHP_EOL;
    $assert($identity['database_name'] === 'passiontech_officeapp', 'Connected schema is the exact disposable fixture schema');
    $existingObjects = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
    $existingRoutines = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema=DATABASE()')->fetchColumn();
    $existingEvents = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.events WHERE event_schema=DATABASE()')->fetchColumn();
    $assert($existingObjects === 0 && $existingRoutines === 0 && $existingEvents === 0, 'Schema is empty before fixture setup; restored/development data cannot be modified');

    $pdo->exec('CREATE TABLE schema_migrations (version VARCHAR(3) NOT NULL PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL) ENGINE=InnoDB');
    $ownedTables[] = 'schema_migrations';
    $pdo->exec('CREATE TABLE schema_migration_steps (version VARCHAR(3) NOT NULL, statement_index INT NOT NULL, PRIMARY KEY(version,statement_index)) ENGINE=InnoDB');
    $ownedTables[] = 'schema_migration_steps';
    $pdo->exec("INSERT INTO schema_migrations VALUES ('099',REPEAT('a',64),'2026-10-01 00:00:00')");
    $ledgerBefore = $pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll();
    $pdo->exec('CREATE TABLE fixture_branches (branch_id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $ownedTables[] = 'fixture_branches';
    $pdo->exec('INSERT INTO fixture_branches VALUES (1),(2)');
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec('CREATE VIEW vw_powerbi_fixture_healthy AS SELECT branch_id FROM fixture_branches');
    $ownedViews[] = 'vw_powerbi_fixture_healthy';

    $healthy = auditRehearsalBaseline($pdo);
    $evidence['healthy'] = $healthy;
    $assert($healthy['result'] === 'PASS' && $healthy['failures'] === [], 'Healthy baseline passes the same permanent audit');
    $assert(($healthy['views']['vw_powerbi_fixture_healthy']['result'] ?? null) === 'PASS', 'Healthy control view is queried successfully');
    $assert(!$pdo->inTransaction(), 'Healthy audit closes its read-only transaction');
    $assert($pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll() === $ledgerBefore, 'Healthy baseline audit does not mutate the 099 ledger');
    $missing=auditRehearsalBaseline($pdo,false,null,['vw_powerbi_required_missing']);
    $assert($missing['result']==='FAIL'&&$missing['failures'][0]['view']==='vw_powerbi_required_missing','Absent required baseline view fails closed');
    $wrong=$healthy['environment'];$wrong['collation_connection']='latin1_swedish_ci';
    $mismatch=auditRehearsalBaseline($pdo,false,$wrong);
    $assert($mismatch['result']==='FAIL'&&isset($mismatch['environment_mismatches']['collation_connection']),'Production environment mismatch fails closed');
    $source=file_get_contents(__DIR__.'/rehearse-production-upgrade.php');
    $gatePosition=strpos($source,"baseline_view_health']['result']==='PASS'");
    $runPosition=strpos($source,'$runner->run(');
    $assert($gatePosition!==false&&$runPosition!==false&&$gatePosition<$runPosition,'Production rehearsal checks the mandatory view-health gate before migration execution');

    // MySQL can validate a nested view using its column metadata at CREATE time,
    // then merge literal expressions at SELECT time and expose mixed COERCIBLE
    // collations. Try a minimal literal and the CASE/UNION shape from the dump.
    // An unreproduced 1270 is a test failure, never a skipped or passing test.
    $candidates = [
        [
            'name' => 'nested literal; unicode inner, general outer',
            'inner' => "SELECT 'SALE' AS flow_type",
            'outer_collation' => 'utf8mb4_general_ci',
        ],
        [
            'name' => 'CASE/UNION; unicode creation, general query',
            'inner' => "SELECT CASE WHEN branch_id=1 THEN 'SALE' ELSE 'RETURN' END AS flow_type FROM fixture_branches WHERE branch_id=1 UNION ALL SELECT 'TRANSFER_OUT' AS flow_type FROM fixture_branches WHERE branch_id=2",
            'outer_collation' => 'utf8mb4_unicode_ci',
        ],
        [
            'name' => 'CASE/UNION; unicode inner, general outer',
            'inner' => "SELECT CASE WHEN branch_id=1 THEN 'SALE' ELSE 'RETURN' END AS flow_type FROM fixture_branches WHERE branch_id=1 UNION ALL SELECT 'TRANSFER_OUT' AS flow_type FROM fixture_branches WHERE branch_id=2",
            'outer_collation' => 'utf8mb4_general_ci',
        ],
    ];
    $mixedName = 'vw_powerbi_fixture_mixed';
    $inputName = 'vw_powerbi_fixture_input';
    $mixedQuery = 'SELECT * FROM `' . $mixedName . '` LIMIT 1';
    $reproduced = null;
    foreach ($candidates as $candidate) {
        $pdo->exec('DROP VIEW IF EXISTS ' . $quote($mixedName));
        $pdo->exec('DROP VIEW IF EXISTS ' . $quote($inputName));
        $attempt = ['candidate' => $candidate['name'], 'query' => $mixedQuery];
        try {
            $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('CREATE ALGORITHM=UNDEFINED VIEW ' . $quote($inputName) . ' AS ' . $candidate['inner']);
            if (!in_array($inputName, $ownedViews, true)) $ownedViews[] = $inputName;
            $pdo->exec('SET NAMES utf8mb4 COLLATE ' . $candidate['outer_collation']);
            $pdo->exec('CREATE ALGORITHM=UNDEFINED VIEW ' . $quote($mixedName) . ' AS SELECT flow_type FROM ' . $quote($inputName) . " WHERE flow_type IN ('SALE','RETURN')");
            if (!in_array($mixedName, $ownedViews, true)) $ownedViews[] = $mixedName;
            $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');
            $pdo->exec('START TRANSACTION READ ONLY');
            try {
                $pdo->query($mixedQuery)->fetchAll();
                $attempt['probe'] = 'SELECT resolved; candidate did not reproduce 1270';
            } catch (PDOException $error) {
                $attempt['probe_error'] = $error->getMessage();
                $attempt['mysql_error_code'] = isset($error->errorInfo[1]) ? (int) $error->errorInfo[1] : null;
                if ($attempt['mysql_error_code'] === 1270) $reproduced = $attempt;
            } finally {
                if ($pdo->inTransaction()) $pdo->rollBack();
            }
        } catch (PDOException $error) {
            $attempt['setup_error'] = $error->getMessage();
            $attempt['mysql_error_code'] = isset($error->errorInfo[1]) ? (int) $error->errorInfo[1] : null;
        }
        $evidence['fixture_candidates'][] = $attempt;
        if ($reproduced !== null) break;
    }
    $assert($reproduced !== null, 'An existing fixture view reproduces MySQL 1270 during SELECT, after successful CREATE');

    $invalid = auditRehearsalBaseline($pdo);
    $evidence['invalid'] = $invalid;
    $migrationCalls = 0;
    $migration = static function () use ($pdo, &$migrationCalls): void {
        ++$migrationCalls;
        $pdo->exec("INSERT INTO schema_migrations VALUES ('100',REPEAT('b',64),'2026-10-01 00:01:00')");
    };
    // Match the production rehearsal's mandatory fail-closed boundary: only a
    // successful audit can enter migration work. The callback is a real write
    // sentinel, so a mistaken entry would also appear in the fixture ledger.
    if ($invalid['result'] === 'PASS') $migration();
    $evidence['migration_callback_calls'] = $migrationCalls;
    $assert($invalid['result'] === 'FAIL', 'Invalid mixed-collation baseline fails the permanent audit');
    $matches = array_values(array_filter($invalid['failures'], static fn (array $failure): bool => $failure['view'] === $mixedName));
    $assert(count($matches) === 1, 'Failure identifies the exact mixed-collation view');
    $assert($matches[0]['query'] === $mixedQuery, 'Failure preserves the exact SELECT query');
    $assert($matches[0]['error'] === $reproduced['probe_error'], 'Failure preserves the full original MySQL 1270 error text');
    $assert(str_contains($matches[0]['error'], '1270') && str_contains($matches[0]['error'], 'Illegal mix of collations'), 'Captured failure is the expected collation error');
    $assert(($invalid['views'][$mixedName]['show_create']['Create View'] ?? '') !== '', 'Failed view SHOW CREATE metadata is retained');
    $assert($migrationCalls === 0, 'Baseline guard never enters the migration callback after failure');
    $evidence['versions_100_or_greater'] = (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version>='100'")->fetchColumn();
    $assert($evidence['versions_100_or_greater'] === 0, 'No migration 100 or greater is applied after failure');
    $assert($pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll() === $ledgerBefore, 'Complete 099 ledger row remains unchanged after failure');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM schema_migration_steps')->fetchColumn() === 0, 'Migration-step ledger remains empty');
    $assert(!$pdo->inTransaction(), 'Failed audit closes its read-only transaction');
    $evidence['result'] = 'PASS';
} catch (Throwable $error) {
    if ($failures === 0) ++$failures;
    $evidence['error'] = $error->getMessage();
    echo 'FAIL: ', $error->getMessage(), PHP_EOL;
} finally {
    // Only named objects created after proving this schema empty are removed.
    if ($pdo instanceof PDO) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        try {
            foreach (array_reverse($ownedViews) as $name) $pdo->exec('DROP VIEW IF EXISTS ' . $quote($name));
            foreach (array_reverse($ownedTables) as $name) $pdo->exec('DROP TABLE IF EXISTS ' . $quote($name));
            $evidence['fixture_cleanup'] = 'PASS';
        } catch (Throwable $cleanupError) {
            ++$failures;
            $evidence['result'] = 'FAIL';
            $evidence['fixture_cleanup'] = $cleanupError->getMessage();
        }
    }
}

echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
echo $checks, ' rehearsal baseline guard checks, ', $failures, ' failures', PHP_EOL;
exit($evidence['result'] === 'PASS' && $failures === 0 ? 0 : 1);
