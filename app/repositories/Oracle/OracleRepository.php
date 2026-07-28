<?php

declare(strict_types=1);

namespace App\Repositories\Oracle;

use App\Database\OracleDialect;
use PDO;
use RuntimeException;

/**
 * Shared boundary for future Oracle repositories.
 */
abstract class OracleRepository
{
    protected function connection(): PDO
    {
        if (\databaseDriver()->name() !== 'oracle') {
            throw new RuntimeException(
                'An Oracle repository requires the Oracle database driver.'
            );
        }

        return \db();
    }

    protected function dialect(): OracleDialect
    {
        $dialect = \databaseDriver()->dialect();

        if (!$dialect instanceof OracleDialect) {
            throw new RuntimeException(
                'An Oracle repository requires the Oracle dialect.'
            );
        }

        return $dialect;
    }
}
