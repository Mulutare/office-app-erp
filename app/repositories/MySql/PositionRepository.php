<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\PositionRepository
    as PositionRepositoryContract;

final class PositionRepository extends MySqlRepository
    implements PositionRepositoryContract
{
    public function listForCompany(
        int $companyId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                positions.position_id,
                positions.code,
                positions.name,
                positions.branch_id,
                positions.department_id,
                positions.job_title_id,
                positions.approved_headcount,
                positions.status,
                positions.description,
                positions.created_at,
                positions.updated_at,
                branches.name AS branch_name,
                departments.name AS department_name,
                job_titles.name AS job_title_name,
                job_titles.grade_level
             FROM organization_positions positions
             LEFT JOIN organization_branches branches
               ON branches.company_id =
                    positions.company_id
              AND branches.branch_id =
                    positions.branch_id
              AND branches.deleted_at IS NULL
             INNER JOIN hr_departments departments
               ON departments.company_id =
                    positions.company_id
              AND departments.department_id =
                    positions.department_id
              AND departments.deleted_at IS NULL
             INNER JOIN organization_job_titles
                job_titles
               ON job_titles.company_id =
                    positions.company_id
              AND job_titles.job_title_id =
                    positions.job_title_id
              AND job_titles.deleted_at IS NULL
             WHERE positions.company_id = :company_id
               AND positions.deleted_at IS NULL
             ORDER BY
                CASE positions.status
                    WHEN \'open\' THEN 1
                    WHEN \'planned\' THEN 2
                    WHEN \'frozen\' THEN 3
                    ELSE 4
                END,
                departments.name,
                positions.name
             LIMIT 500'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
        $positions = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($positions)
            ? $positions
            : [];
    }

    public function find(
        int $companyId,
        int $positionId
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                position_id,
                code,
                name,
                branch_id,
                department_id,
                job_title_id,
                approved_headcount,
                status,
                description,
                created_by,
                updated_by,
                created_at,
                updated_at
             FROM organization_positions
             WHERE company_id = :company_id
               AND position_id = :position_id
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'position_id' => $positionId,
        ]);
        $position = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($position)
            ? $position
            : null;
    }

    public function codeExists(
        int $companyId,
        string $code,
        ?int $ignorePositionId = null
    ): bool {
        $sql = 'SELECT 1
                FROM organization_positions
                WHERE company_id = :company_id
                  AND code = :code
                  AND deleted_at IS NULL';
        $parameters = [
            'company_id' => $companyId,
            'code' => $code,
        ];

        if ($ignorePositionId !== null) {
            $sql .= '
                  AND position_id <>
                      :ignore_position_id';
            $parameters['ignore_position_id'] =
                $ignorePositionId;
        }

        $sql .= '
                LIMIT 1';
        $statement = $this->connection()->prepare(
            $sql
        );
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    public function create(
        int $companyId,
        array $values,
        int $createdBy
    ): int {
        $statement = $this->connection()->prepare(
            'INSERT INTO organization_positions
                (
                    company_id,
                    code,
                    name,
                    branch_id,
                    department_id,
                    job_title_id,
                    approved_headcount,
                    status,
                    description,
                    created_by,
                    updated_by
                )
             VALUES
                (
                    :company_id,
                    :code,
                    :name,
                    :branch_id,
                    :department_id,
                    :job_title_id,
                    :approved_headcount,
                    :status,
                    :description,
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
        int $positionId,
        array $values,
        int $updatedBy
    ): bool {
        $statement = $this->connection()->prepare(
            'UPDATE organization_positions
             SET code = :code,
                 name = :name,
                 branch_id = :branch_id,
                 department_id = :department_id,
                 job_title_id = :job_title_id,
                 approved_headcount =
                    :approved_headcount,
                 status = :status,
                 description = :description,
                 updated_by = :updated_by
             WHERE company_id = :company_id
               AND position_id = :position_id
               AND deleted_at IS NULL'
        );
        $parameters = $this->writeParameters(
            $companyId,
            $values,
            $updatedBy
        );
        unset($parameters['created_by']);
        $parameters['position_id'] = $positionId;
        $statement->execute($parameters);

        return $statement->rowCount() > 0;
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
            'branch_id' => $values['branch_id'],
            'department_id' =>
                $values['department_id'],
            'job_title_id' =>
                $values['job_title_id'],
            'approved_headcount' =>
                $values['approved_headcount'],
            'status' => $values['status'],
            'description' => $values['description'],
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ];
    }
}
