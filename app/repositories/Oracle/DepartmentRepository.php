<?php

declare(strict_types=1);

namespace App\Repositories\Oracle;

use App\Repositories\DepartmentRepository
    as DepartmentRepositoryContract;

final class DepartmentRepository extends OracleRepository
    implements DepartmentRepositoryContract
{
    public function activeOptions(int $companyId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT
                department_id,
                code,
                name,
                parent_department_id
             FROM hr_departments
             WHERE company_id = :company_id
               AND active = 1
               AND deleted_at IS NULL
             ORDER BY name
             FETCH FIRST 250 ROWS ONLY'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
        $departments = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($departments)
            ? $departments
            : [];
    }

    public function listForCompany(
        int $companyId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                departments.department_id,
                departments.code,
                departments.name,
                departments.parent_department_id,
                parent.name AS parent_department_name,
                departments.description,
                departments.active,
                departments.created_at,
                departments.updated_at,
                COUNT(employees.employee_id)
                    AS employee_count,
                SUM(
                    CASE
                        WHEN employees.employee_id IS NOT NULL
                         AND employees.employment_status
                            <> \'terminated\'
                        THEN 1
                        ELSE 0
                    END
                ) AS current_employee_count
             FROM hr_departments departments
             LEFT JOIN hr_employees employees
                 ON employees.department_id =
                    departments.department_id
                AND employees.company_id =
                    departments.company_id
                AND employees.deleted_at IS NULL
             LEFT JOIN hr_departments parent
                 ON parent.department_id =
                    departments.parent_department_id
                AND parent.company_id =
                    departments.company_id
                AND parent.deleted_at IS NULL
             WHERE departments.company_id =
                    :company_id
               AND departments.deleted_at IS NULL
             GROUP BY
                departments.department_id,
                departments.code,
                departments.name,
                departments.parent_department_id,
                parent.name,
                departments.description,
                departments.active,
                departments.created_at,
                departments.updated_at
             ORDER BY
                departments.active DESC,
                departments.name
             FETCH FIRST 250 ROWS ONLY'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
        $departments = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($departments)
            ? $departments
            : [];
    }

    public function find(
        int $companyId,
        int $departmentId
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                department_id,
                code,
                name,
                parent_department_id,
                description,
                active,
                created_by,
                updated_by,
                created_at,
                updated_at
             FROM hr_departments
             WHERE company_id = :company_id
               AND department_id = :department_id
               AND deleted_at IS NULL
             FETCH FIRST 1 ROWS ONLY'
        );
        $statement->execute([
            'company_id' => $companyId,
            'department_id' => $departmentId,
        ]);
        $department = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($department)
            ? $department
            : null;
    }

    public function codeExists(
        int $companyId,
        string $code,
        ?int $ignoreDepartmentId = null
    ): bool {
        return $this->valueExists(
            $companyId,
            'code',
            $code,
            $ignoreDepartmentId
        );
    }

    public function nameExists(
        int $companyId,
        string $name,
        ?int $ignoreDepartmentId = null
    ): bool {
        return $this->valueExists(
            $companyId,
            'name',
            $name,
            $ignoreDepartmentId
        );
    }

    public function activeExists(
        int $companyId,
        int $departmentId
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM hr_departments
             WHERE company_id = :company_id
               AND department_id = :department_id
               AND active = 1
               AND deleted_at IS NULL
             FETCH FIRST 1 ROWS ONLY'
        );
        $statement->execute([
            'company_id' => $companyId,
            'department_id' => $departmentId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function currentEmployeeCount(
        int $companyId,
        int $departmentId
    ): int {
        $statement = $this->connection()->prepare(
            'SELECT COUNT(*)
             FROM hr_employees
             WHERE company_id = :company_id
               AND department_id = :department_id
               AND employment_status <> \'terminated\'
               AND deleted_at IS NULL'
        );
        $statement->execute([
            'company_id' => $companyId,
            'department_id' => $departmentId,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function activeChildCount(
        int $companyId,
        int $departmentId
    ): int {
        $statement = $this->connection()->prepare(
            'SELECT COUNT(*)
             FROM hr_departments
             WHERE company_id = :company_id
               AND parent_department_id =
                    :department_id
               AND active = 1
               AND deleted_at IS NULL'
        );
        $statement->execute([
            'company_id' => $companyId,
            'department_id' => $departmentId,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function create(
        int $companyId,
        array $values,
        int $createdBy
    ): int {
        $statement = $this->connection()->prepare(
            'INSERT INTO hr_departments
                (
                    company_id,
                    code,
                    name,
                    parent_department_id,
                    description,
                    active,
                    created_by,
                    updated_by
                )
             VALUES
                (
                    :company_id,
                    :code,
                    :name,
                    :parent_department_id,
                    :description,
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

        $lookup = $this->connection()->prepare(
            'SELECT department_id
             FROM hr_departments
             WHERE company_id = :company_id
               AND code = :code
               AND deleted_at IS NULL
             FETCH FIRST 1 ROWS ONLY'
        );
        $lookup->execute([
            'company_id' => $companyId,
            'code' => $values['code'],
        ]);

        return (int) $lookup->fetchColumn();
    }

    public function update(
        int $companyId,
        int $departmentId,
        array $values,
        int $updatedBy
    ): bool {
        $statement = $this->connection()->prepare(
            'UPDATE hr_departments
             SET code = :code,
                 name = :name,
                 parent_department_id =
                    :parent_department_id,
                 description = :description,
                 active = :active,
                 updated_by = :updated_by,
                 updated_at = SYSTIMESTAMP
             WHERE company_id = :company_id
               AND department_id = :department_id
               AND deleted_at IS NULL'
        );
        $parameters = $this->writeParameters(
            $companyId,
            $values,
            $updatedBy
        );
        unset($parameters['created_by']);
        $parameters['department_id'] = $departmentId;
        $statement->execute($parameters);

        return $statement->rowCount() > 0;
    }

    private function valueExists(
        int $companyId,
        string $column,
        string $value,
        ?int $ignoreDepartmentId
    ): bool {
        if (!in_array(
            $column,
            ['code', 'name'],
            true
        )) {
            throw new \InvalidArgumentException(
                'Unsupported department uniqueness column.'
            );
        }

        $sql = 'SELECT 1
                FROM hr_departments
                WHERE company_id = :company_id
                  AND ' . $column . ' = :value
                  AND deleted_at IS NULL';
        $parameters = [
            'company_id' => $companyId,
            'value' => $value,
        ];

        if ($ignoreDepartmentId !== null) {
            $sql .= '
                  AND department_id <>
                      :ignore_department_id';
            $parameters['ignore_department_id'] =
                $ignoreDepartmentId;
        }

        $sql .= '
                FETCH FIRST 1 ROWS ONLY';
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
            'parent_department_id' =>
                $values['parent_department_id'],
            'description' => $values['description'],
            'active' => !empty($values['active'])
                ? 1
                : 0,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ];
    }
}
