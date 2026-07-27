<?php

declare(strict_types=1);

namespace App\Services;

final class TenantContext
{
    public function companyIdOrNull(): ?int
    {
        $companyId = $_SESSION['auth']['company'][
            'company_id'
        ] ?? null;

        return is_int($companyId) && $companyId > 0
            ? $companyId
            : null;
    }

    public function companyId(): int
    {
        $companyId = $this->companyIdOrNull();

        if ($companyId === null) {
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
