<?php

declare(strict_types=1);

namespace App\Repositories\Oracle;

use App\Repositories\DashboardStatisticsRepository
    as DashboardStatisticsRepositoryContract;
use LogicException;

/**
 * Fail-closed placeholder pending real Oracle query validation.
 */
final class DashboardStatisticsRepository
    extends OracleRepository
    implements DashboardStatisticsRepositoryContract
{
    public function statistics(int $companyId): array
    {
        throw new LogicException(
            'The Oracle dashboard repository is not implemented.'
        );
    }
}
