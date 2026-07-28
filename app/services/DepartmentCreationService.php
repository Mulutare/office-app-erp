<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Backward-compatible facade for legacy HR routes.
 */
final class DepartmentCreationService
{
    private DepartmentCatalogueService $catalogue;

    public function __construct(
        ?DepartmentCatalogueService $catalogue = null
    ) {
        $this->catalogue = $catalogue
            ?? new DepartmentCatalogueService();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function create(
        array $input,
        int $createdBy
    ): array {
        return $this->catalogue->create(
            $input,
            $createdBy
        );
    }
}
