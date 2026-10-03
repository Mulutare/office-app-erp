<?php

declare(strict_types=1);

$root = dirname(__DIR__);

require $root . '/app/helpers/bootstrap.php';

if (
    getenv('APP_ENV') !== 'testing'
    || getenv('DB_DATABASE') !== 'office_app_test'
) {
    throw new RuntimeException('Disposable recruitment database required.');
}

$pdo = db();
$directory = $root . '/database/migrations/mysql';
$runner = new App\Database\MigrationRunner($pdo, 'mysql');

$checks = 0;
$failures = 0;

$check = static function (bool $ok, string $label) use (&$checks, &$failures): void {
    $checks++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;

    if (!$ok) {
        $failures++;
    }
};

$reject = static function (callable $action): bool {
    try {
        $action();
        return false;
    } catch (Throwable) {
        return true;
    }
};

$audit = $runner->auditAppliedMigrations($directory);

$check(
    ($audit['applied_versions'][array_key_last($audit['applied_versions'])] ?? null) === '111'
    && $audit['first_unapplied'] === null,
    'Reviewed migration catalog is fully applied through Careers 111'
);

$expected = array_map(
    static fn (int $version): string => sprintf('%03d', $version),
    range(15, 111)
);

$check(
    $audit['applied_versions'] === $expected,
    'Migration ledger is the exact ordered 015-111 sequence'
);

$migration = require $directory . '/110_recruitment.php';

$check(
    ($migration['version'] ?? null) === '110'
    && str_contains(
        (string) ($migration['description'] ?? ''),
        'Licensed company-scoped recruitment'
    ),
    'Migration 110 is the reviewed licensed Recruitment migration'
);

$check(
    is_file($directory . '/110_recruitment.php')
    && !is_file($directory . '/111_recruitment.php'),
    'Recruitment occupies 110 and no obsolete Recruitment 111 exists'
);

$pdo->beginTransaction();

try {
    $pdo->exec(
        "DELETE FROM schema_migrations
         WHERE version='111'"
    );

    $prefix = $runner->auditAppliedMigrations($directory);

    $check(
        ($prefix['applied_versions'][array_key_last($prefix['applied_versions'])] ?? null) === '110'
        && $prefix['first_unapplied'] === '111',
        'Verified production-110 prefix identifies Careers 111 as exactly next'
    );
} finally {
    $pdo->rollBack();
}

$pdo->beginTransaction();

try {
    $pdo->exec(
        "UPDATE schema_migrations
         SET checksum=REPEAT('a',64)
         WHERE version='110'"
    );

    $check(
        $reject(
            static fn () =>
                $runner->auditAppliedMigrations($directory)
        ),
        'Release ledger rejects a modified Recruitment 110 checksum'
    );
} finally {
    $pdo->rollBack();
}

$pdo->beginTransaction();

try {
    $pdo->exec(
        "INSERT INTO schema_migrations
            (version,description,checksum)
         VALUES
            ('112','Synthetic future migration',REPEAT('b',64))"
    );

    $check(
        $reject(
            static fn () =>
                $runner->auditAppliedMigrations($directory)
        ),
        'Release ledger rejects an unknown future migration after 111'
    );
} finally {
    $pdo->rollBack();
}

$runnerSource = file_get_contents(
    $root . '/deployment/production-runner.php'
);

$check(
    str_contains(
        $runnerSource,
        "\$expectedVersion==='111'?'migrated':'migrating'"
    )
    && str_contains(
        $runnerSource,
        "\$current!=='111'"
    )
    && str_contains(
        $runnerSource,
        "\$result['release_target']='111'"
    ),
    'Production runner requires completed target 111 before sync and cutover health'
);

$commonSource = file_get_contents(
    $root . '/tools/deployment-release-common.ps1'
);

$check(
    str_contains(
        $commonSource,
        "[-1]-ne'110'"
    )
    && str_contains(
        $commonSource,
        "first_unapplied-ne'111'"
    )
    && str_contains(
        $commonSource,
        "first_preflight-ne'apply'"
    ),
    'Staged deployment audit requires verified 110 then apply-ready 111'
);

$validationSource = file_get_contents(
    $root . '/deployment/powerbi-upgrade-validation.php'
);

$check(
    str_contains(
        $validationSource,
        "['099', '109', '110', '111']"
    )
    && str_contains(
        $validationSource,
        'range(15, (int)$target)'
    ),
    'Release health preserves Power BI validation while recognizing additive target 111'
);

echo "$checks recruitment release-contract checks, $failures failures\n";

exit($failures === 0 ? 0 : 1);