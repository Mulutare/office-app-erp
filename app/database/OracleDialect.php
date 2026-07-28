<?php

declare(strict_types=1);

namespace App\Database;

use InvalidArgumentException;

/**
 * Oracle SQL expressions for future Oracle repository implementations.
 */
final class OracleDialect implements SqlDialect
{
    public function name(): string
    {
        return 'oracle';
    }

    public function currentTimestampExpression(): string
    {
        return 'SYSTIMESTAMP';
    }

    public function todayRangePredicate(
        string $qualifiedColumn
    ): string {
        $column = $this->qualifiedColumn(
            $qualifiedColumn
        );

        return sprintf(
            '%s >= TRUNC(CURRENT_DATE)'
            . ' AND %s < TRUNC(CURRENT_DATE) + 1',
            $column,
            $column
        );
    }

    public function paginationClause(): string
    {
        return 'OFFSET :offset ROWS'
            . ' FETCH NEXT :limit ROWS ONLY';
    }

    public function firstRowClause(): string
    {
        return 'FETCH FIRST 1 ROWS ONLY';
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
