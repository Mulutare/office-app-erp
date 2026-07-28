<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Database\MySqlDialect;
use PDO;
use RuntimeException;

/**
 * Shared connection and dialect access for MySQL repositories.
 */
abstract class MySqlRepository
{
    protected function connection(): PDO
    {
        return \db();
    }

    protected function dialect(): MySqlDialect
    {
        $dialect = \databaseDriver()->dialect();

        if (!$dialect instanceof MySqlDialect) {
            throw new RuntimeException(
                'A MySQL repository requires the MySQL dialect.'
            );
        }

        return $dialect;
    }
}
