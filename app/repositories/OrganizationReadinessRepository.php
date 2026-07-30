<?php

declare(strict_types=1);

namespace App\Repositories;

interface OrganizationReadinessRepository
{
    /**
     * Return tenant-scoped aggregate organization metrics.
     *
     * @return array<string, int|string|null>
     */
    public function snapshot(int $companyId): array;
}
