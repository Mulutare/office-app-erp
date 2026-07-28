<?php

declare(strict_types=1);

namespace App\Database;

/**
 * Supplies database-specific SQL fragments to repository implementations.
 */
interface SqlDialect
{
    public function name(): string;

    public function currentTimestampExpression(): string;

    public function todayRangePredicate(
        string $qualifiedColumn
    ): string;

    public function paginationClause(): string;

    public function firstRowClause(): string;
}
