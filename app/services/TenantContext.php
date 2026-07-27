<?php

declare(strict_types=1);

namespace App\Services;

final class TenantContext
{
    public function companyId(): int
    {
        $companyId = $_SESSION['auth']['company'][
            'company_id'
        ] ?? null;

        if (!is_int($companyId)) {
            throw new \RuntimeException(
                'An active company workspace is required.'
            );
        }

        return $companyId;
    }

    /**
     * @return array<string, mixed>
     */
    public function company(): array
    {
        $company = $_SESSION['auth']['company']
            ?? null;

        if (!is_array($company)) {
            throw new \RuntimeException(
                'An active company workspace is required.'
            );
        }

        return $company;
    }
}
