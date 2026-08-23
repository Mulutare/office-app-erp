<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/bootstrap.php';

use App\Database\MigrationRunner;

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $description) use (
    &$passed,
    &$failed
): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $description . PHP_EOL;
    $condition ? $passed++ : $failed++;
};

$connection = db();
$runner = new MigrationRunner($connection, 'mysql');
$legacyChecksum =
    '0392c26a00f8ef3ff11d9b8fc496d207116b32b87f17a6e35d19c8c06e163fcb';
$wrongChecksum = str_repeat('f', 64);
$fixtureRoot = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'officeapp-migration-checksum-'
    . bin2hex(random_bytes(8));
$migration040 = dirname(__DIR__)
    . '/database/migrations/mysql/040_location_aware_stock_movements.php';
$original040Checksum = null;

try {
    if (!mkdir($fixtureRoot, 0700, true) && !is_dir($fixtureRoot)) {
        throw new RuntimeException('Unable to create migration test fixtures.');
    }

    if (!copy($migration040, $fixtureRoot . '/040_fixture.php')) {
        throw new RuntimeException('Unable to copy migration 040 test fixture.');
    }

    $checksumStatement = $connection->prepare(
        'SELECT checksum FROM schema_migrations WHERE version = :version'
    );
    $checksumStatement->execute(['version' => '040']);
    $original040Checksum = $checksumStatement->fetchColumn();

    if (!is_string($original040Checksum)) {
        throw new RuntimeException('Migration 040 is missing from the test ledger.');
    }

    $result = $runner->run($fixtureRoot);
    $check(
        $result['skipped'] === ['040'],
        'Exact current migration checksum is accepted'
    );

    $updateChecksum = $connection->prepare(
        'UPDATE schema_migrations
         SET checksum = :checksum
         WHERE version = :version'
    );
    $updateChecksum->execute([
        'checksum' => $legacyChecksum,
        'version' => '040',
    ]);
    $result = $runner->run($fixtureRoot);
    $check(
        $result['skipped'] === ['040'],
        'Migration 040 accepts the exact known production legacy checksum'
    );

    file_put_contents(
        $fixtureRoot . '/040_fixture.php',
        "\n// Simulate a future substantive migration-file modification.\n",
        FILE_APPEND
    );
    $alteredCurrentRejected = false;

    try {
        $runner->run($fixtureRoot);
    } catch (RuntimeException $exception) {
        $alteredCurrentRejected = $exception->getMessage()
            === 'An applied migration was modified: 040';
    }

    $check(
        $alteredCurrentRejected,
        'Migration 040 legacy checksum is rejected if current contents change'
    );

    if (!copy($migration040, $fixtureRoot . '/040_fixture.php')) {
        throw new RuntimeException('Unable to restore migration 040 test fixture.');
    }

    $updateChecksum->execute([
        'checksum' => $wrongChecksum,
        'version' => '040',
    ]);
    $wrong040Rejected = false;

    try {
        $runner->run($fixtureRoot);
    } catch (RuntimeException $exception) {
        $wrong040Rejected = $exception->getMessage()
            === 'An applied migration was modified: 040';
    }

    $check(
        $wrong040Rejected,
        'Migration 040 rejects every unrecognized checksum'
    );

    $updateChecksum->execute([
        'checksum' => $original040Checksum,
        'version' => '040',
    ]);
    unlink($fixtureRoot . '/040_fixture.php');
    file_put_contents(
        $fixtureRoot . '/999_fixture.php',
        "<?php\nreturn [\n"
        . "    'version' => '999',\n"
        . "    'description' => 'Checksum isolation fixture',\n"
        . "    'statements' => ['SELECT 1'],\n"
        . "    'preflight' => null,\n"
        . "];\n"
    );
    $connection->prepare(
        'INSERT INTO schema_migrations (version, description, checksum)
         VALUES (:version, :description, :checksum)'
    )->execute([
        'version' => '999',
        'description' => 'Checksum isolation fixture',
        'checksum' => $legacyChecksum,
    ]);
    $otherVersionRejected = false;

    try {
        $runner->run($fixtureRoot);
    } catch (RuntimeException $exception) {
        $otherVersionRejected = $exception->getMessage()
            === 'An applied migration was modified: 999';
    }

    $check(
        $otherVersionRejected,
        'The migration 040 legacy checksum is rejected for every other version'
    );
    $connection->prepare(
        'DELETE FROM schema_migrations WHERE version = :version'
    )->execute(['version' => '999']);
    unlink($fixtureRoot . '/999_fixture.php');
    file_put_contents(
        $fixtureRoot . '/998_fixture.php',
        "<?php\nreturn [\n"
        . "    'version' => '998',\n"
        . "    'description' => 'Partial migration fixture',\n"
        . "    'statements' => ['SELECT 1'],\n"
        . "    'preflight' => null,\n"
        . "];\n"
    );
    $connection->prepare(
        'INSERT INTO schema_migration_steps (
            version,
            statement_number,
            migration_checksum,
            statement_checksum
         ) VALUES (:version, 1, :migration_checksum, :statement_checksum)'
    )->execute([
        'version' => '998',
        'migration_checksum' => $wrongChecksum,
        'statement_checksum' => hash('sha256', 'SELECT 1'),
    ]);
    $partialMismatchRejected = false;

    try {
        $runner->run($fixtureRoot);
    } catch (RuntimeException $exception) {
        $partialMismatchRejected = $exception->getMessage()
            === 'A partially applied migration was modified: 998';
    }

    $check(
        $partialMismatchRejected,
        'Partial migration step checksum mismatches remain rejected'
    );
} catch (Throwable $exception) {
    echo 'FAIL unexpected: ' . $exception->getMessage() . PHP_EOL;
    $failed++;
} finally {
    if (is_string($original040Checksum)) {
        $connection->prepare(
            'UPDATE schema_migrations
             SET checksum = :checksum
             WHERE version = :version'
        )->execute([
            'checksum' => $original040Checksum,
            'version' => '040',
        ]);
    }

    $connection->prepare(
        "DELETE FROM schema_migration_steps WHERE version IN ('998', '999')"
    )->execute();
    $connection->prepare(
        "DELETE FROM schema_migrations WHERE version IN ('998', '999')"
    )->execute();

    foreach (['040_fixture.php', '998_fixture.php', '999_fixture.php'] as $file) {
        $path = $fixtureRoot . DIRECTORY_SEPARATOR . $file;

        if (is_file($path)) {
            unlink($path);
        }
    }

    if (is_dir($fixtureRoot)) {
        rmdir($fixtureRoot);
    }
}

echo PHP_EOL . ($passed + $failed)
    . ' migration checksum checks, '
    . $failed
    . ' failures'
    . PHP_EOL;

exit($failed === 0 ? 0 : 1);
