<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Backward-compatible facade for legacy HR routes.
 */
final class DepartmentManagementService
{
    private DepartmentCatalogueService $catalogue;

    public function __construct(
        ?DepartmentCatalogueService $catalogue = null
    ) {
        $this->catalogue = $catalogue
            ?? new DepartmentCatalogueService();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listing(): array
    {
        return $this->catalogue->listing();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function form(int $departmentId): ?array
    {
        return $this->catalogue->form($departmentId);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function update(
        int $departmentId,
        array $input,
        int $updatedBy
    ): array {
        if (!array_key_exists(
            'parent_department_id',
            $input
        )) {
            $current = $this->catalogue->form(
                $departmentId
            );

            if ($current !== null) {
                $input['parent_department_id'] =
                    $current['parent_department_id']
                        ?? null;
            }
        }

        return $this->catalogue->update(
            $departmentId,
            $input,
            $updatedBy
        );
    }
}
