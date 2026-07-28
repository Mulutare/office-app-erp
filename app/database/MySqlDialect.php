<?php

declare(strict_types=1);

namespace App\Database;

use InvalidArgumentException;

/**
 * MySQL 8 and MariaDB SQL expressions used by repositories.
 */
final class MySqlDialect implements SqlDialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function currentTimestampExpression(): string
    {
        return 'NOW()';
    }

    public function todayRangePredicate(
        string $qualifiedColumn
    ): string {
        $column = $this->qualifiedColumn(
            $qualifiedColumn
        );

        return sprintf(
            '%s >= CURDATE()'
            . ' AND %s < CURDATE() + INTERVAL 1 DAY',
            $column,
            $column
        );
    }

    public function paginationClause(): string
    {
        return 'LIMIT :limit OFFSET :offset';
    }

    public function firstRowClause(): string
    {
        return 'LIMIT 1';
    }

    private function qualifiedColumn(
        string $qualifiedColumn
    ): string {
        if (
            preg_match(
                '/^[A-Za-z_][A-Za-z0-9_]*'
                . '(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/',
                $qualifiedColumn
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'The SQL column identifier is not allowlisted.'
            );
        }

        return $qualifiedColumn;
    }
}
