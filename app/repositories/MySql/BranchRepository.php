<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\BranchRepository as BranchRepositoryContract;

final class BranchRepository extends MySqlRepository
    implements BranchRepositoryContract
{
    public function lockCompany(int $companyId): void
    {
        $statement = $this->connection()->prepare(
            'SELECT company_id
             FROM companies
             WHERE company_id = :company_id
               AND deleted_at IS NULL
             FOR UPDATE'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);

        if ($statement->fetchColumn() === false) {
            throw new \RuntimeException(
                'The active company workspace was not found.'
            );
        }
    }

    public function listForCompany(
        int $companyId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                branch_id,
                code,
                name,
                contact_email,
                contact_phone,
                address_line,
                city,
                country_code,
                timezone,
                attendance_geofence_enabled,
                attendance_latitude,
                attendance_longitude,
                attendance_radius_meters,
                is_head_office,
                active,
                created_at,
                updated_at
             FROM organization_branches
             WHERE company_id = :company_id
               AND deleted_at IS NULL
             ORDER BY
                is_head_office DESC,
                active DESC,
                name
             LIMIT 250'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
        $branches = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($branches)
            ? $branches
            : [];
    }

    public function find(
        int $companyId,
        int $branchId
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                branch_id,
                code,
                name,
                contact_email,
                contact_phone,
                address_line,
                city,
                country_code,
                timezone,
                attendance_geofence_enabled,
                attendance_latitude,
                attendance_longitude,
                attendance_radius_meters,
                is_head_office,
                active,
                created_by,
                updated_by,
                created_at,
                updated_at
             FROM organization_branches
             WHERE company_id = :company_id
               AND branch_id = :branch_id
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'branch_id' => $branchId,
        ]);
        $branch = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($branch)
            ? $branch
            : null;
    }

    public function codeExists(
        int $companyId,
        string $code,
        ?int $ignoreBranchId = null
    ): bool {
        return $this->valueExists(
            $companyId,
            'code',
            $code,
            $ignoreBranchId
        );
    }

    public function nameExists(
        int $companyId,
        string $name,
        ?int $ignoreBranchId = null
    ): bool {
        return $this->valueExists(
            $companyId,
            'name',
            $name,
            $ignoreBranchId
        );
    }

    public function headOfficeId(
        int $companyId,
        ?int $ignoreBranchId = null,
        bool $lock = false
    ): ?int {
        $sql = 'SELECT branch_id
                FROM organization_branches
                WHERE company_id = :company_id
                  AND is_head_office = TRUE
                  AND deleted_at IS NULL';
        $parameters = [
            'company_id' => $companyId,
        ];

        if ($ignoreBranchId !== null) {
            $sql .= '
                  AND branch_id <> :ignore_branch_id';
            $parameters['ignore_branch_id'] =
                $ignoreBranchId;
        }

        $sql .= '
                ORDER BY branch_id
                LIMIT 1';

        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->connection()->prepare(
            $sql
        );
        $statement->execute($parameters);
        $branchId = $statement->fetchColumn();

        return $branchId === false
            ? null
            : (int) $branchId;
    }

    public function create(
        int $companyId,
        array $values,
        int $createdBy
    ): int {
        $statement = $this->connection()->prepare(
            'INSERT INTO organization_branches
                (
                    company_id,
                    code,
                    name,
                    contact_email,
                    contact_phone,
                    address_line,
                    city,
                    country_code,
                    timezone,
                    attendance_geofence_enabled,
                    attendance_latitude,
                    attendance_longitude,
                    attendance_radius_meters,
                    is_head_office,
                    active,
                    created_by,
                    updated_by
                )
             VALUES
                (
                    :company_id,
                    :code,
                    :name,
                    :contact_email,
                    :contact_phone,
                    :address_line,
                    :city,
                    :country_code,
                    :timezone,
                    :attendance_geofence_enabled,
                    :attendance_latitude,
                    :attendance_longitude,
                    :attendance_radius_meters,
                    :is_head_office,
                    :active,
                    :created_by,
                    :updated_by
                )'
        );
        $statement->execute(
            $this->writeParameters(
                $companyId,
                $values,
                $createdBy
            )
        );

        return (int) $this->connection()
            ->lastInsertId();
    }

    public function update(
        int $companyId,
        int $branchId,
        array $values,
        int $updatedBy
    ): bool {
        $statement = $this->connection()->prepare(
            'UPDATE organization_branches
             SET code = :code,
                 name = :name,
                 contact_email = :contact_email,
                 contact_phone = :contact_phone,
                 address_line = :address_line,
                 city = :city,
                 country_code = :country_code,
                 timezone = :timezone,
                 attendance_geofence_enabled = :attendance_geofence_enabled,
                 attendance_latitude = :attendance_latitude,
                 attendance_longitude = :attendance_longitude,
                 attendance_radius_meters = :attendance_radius_meters,
                 is_head_office = :is_head_office,
                 active = :active,
                 updated_by = :updated_by
             WHERE company_id = :company_id
               AND branch_id = :branch_id
               AND deleted_at IS NULL'
        );
        $parameters = $this->writeParameters(
            $companyId,
            $values,
            $updatedBy
        );
        unset($parameters['created_by']);
        $parameters['branch_id'] = $branchId;
        $statement->execute($parameters);

        return $statement->rowCount() > 0;
    }

    private function valueExists(
        int $companyId,
        string $column,
        string $value,
        ?int $ignoreBranchId
    ): bool {
        if (!in_array(
            $column,
            ['code', 'name'],
            true
        )) {
            throw new \InvalidArgumentException(
                'Unsupported branch uniqueness column.'
            );
        }

        $sql = 'SELECT 1
             FROM organization_branches
             WHERE company_id = :company_id
               AND ' . $column . ' = :value
               AND deleted_at IS NULL';
        $parameters = [
            'company_id' => $companyId,
            'value' => $value,
        ];

        if ($ignoreBranchId !== null) {
            $sql .= '
               AND branch_id <> :ignore_branch_id';
            $parameters['ignore_branch_id'] =
                $ignoreBranchId;
        }

        $sql .= '
             LIMIT 1';
        $statement = $this->connection()->prepare(
            $sql
        );
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function writeParameters(
        int $companyId,
        array $values,
        int $actorId
    ): array {
        return [
            'company_id' => $companyId,
            'code' => $values['code'],
            'name' => $values['name'],
            'contact_email' =>
                $values['contact_email'],
            'contact_phone' =>
                $values['contact_phone'],
            'address_line' =>
                $values['address_line'],
            'city' => $values['city'],
            'country_code' =>
                $values['country_code'],
            'timezone' => $values['timezone'],
            'attendance_geofence_enabled' =>
                !empty($values['attendance_geofence_enabled']) ? 1 : 0,
            'attendance_latitude' =>
                $values['attendance_latitude'],
            'attendance_longitude' =>
                $values['attendance_longitude'],
            'attendance_radius_meters' =>
                $values['attendance_radius_meters'],
            'is_head_office' =>
                !empty($values['is_head_office'])
                    ? 1
                    : 0,
            'active' => !empty($values['active'])
                ? 1
                : 0,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ];
    }
}
