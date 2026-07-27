<?php

declare(strict_types=1);

namespace App\Models;

final class Department
{
    /**
     * @return list<array<string, mixed>>
     */
    public function activeOptions(int $companyId): array
    {
        $statement = \db()->prepare(
            'SELECT
                department_id,
                code,
                name
             FROM hr_departments
             WHERE company_id = :company_id
               AND active = TRUE
               AND deleted_at IS NULL
             ORDER BY name'
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

    public function codeExists(
        int $companyId,
        string $code,
        ?int $ignoreDepartmentId = null
    ): bool {
        $statement = \db()->prepare(
            'SELECT 1
             FROM hr_departments
             WHERE company_id = :company_id
               AND code = :code
               AND deleted_at IS NULL
               AND (
                   :ignore_department_null IS NULL
                   OR department_id
                        <> :ignore_department_value
               )
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'code' => $code,
            'ignore_department_null' =>
                $ignoreDepartmentId,
            'ignore_department_value' =>
                $ignoreDepartmentId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function nameExists(
        int $companyId,
        string $name,
        ?int $ignoreDepartmentId = null
    ): bool {
        $statement = \db()->prepare(
            'SELECT 1
             FROM hr_departments
             WHERE company_id = :company_id
               AND name = :name
               AND deleted_at IS NULL
               AND (
                   :ignore_department_null IS NULL
                   OR department_id
                        <> :ignore_department_value
               )
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'name' => $name,
            'ignore_department_null' =>
                $ignoreDepartmentId,
            'ignore_department_value' =>
                $ignoreDepartmentId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function activeExists(
        int $companyId,
        int $departmentId
    ): bool {
        $statement = \db()->prepare(
            'SELECT 1
             FROM hr_departments
             WHERE company_id = :company_id
               AND department_id = :department_id
               AND active = TRUE
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'department_id' => $departmentId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function create(
        int $companyId,
        string $code,
        string $name,
        string $description,
        bool $active,
        int $createdBy
    ): int {
        $statement = \db()->prepare(
            'INSERT INTO hr_departments
                (
                    company_id,
                    code,
                    name,
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
                    :description,
                    :active,
                    :created_by,
                    :updated_by
                )'
        );
        $statement->execute([
            'company_id' => $companyId,
            'code' => $code,
            'name' => $name,
            'description' =>
                $description === ''
                    ? null
                    : $description,
            'active' => $active ? 1 : 0,
            'created_by' => $createdBy,
            'updated_by' => $createdBy,
        ]);

        return (int) \db()->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function managementList(int $companyId): array
    {
        $statement = \db()->prepare(
            'SELECT
                departments.department_id,
                departments.code,
                departments.name,
                departments.description,
                departments.active,
                departments.created_at,
                departments.updated_at,
                COUNT(employees.employee_id)
                    AS employee_count,
                SUM(
                    CASE
                        WHEN employees.employment_status
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
             WHERE departments.company_id =
                    :company_id
               AND departments.deleted_at IS NULL
             GROUP BY
                departments.department_id,
                departments.code,
                departments.name,
                departments.description,
                departments.active,
                departments.created_at,
                departments.updated_at
             ORDER BY
                departments.active DESC,
                departments.name'
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

    /**
     * @return array<string, mixed>|null
     */
    public function find(
        int $companyId,
        int $departmentId
    ): ?array {
        $statement = \db()->prepare(
            'SELECT
                department_id,
                code,
                name,
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
             LIMIT 1'
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

    public function currentEmployeeCount(
        int $companyId,
        int $departmentId
    ): int {
        $statement = \db()->prepare(
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

    public function update(
        int $companyId,
        int $departmentId,
        string $code,
        string $name,
        string $description,
        bool $active,
        int $updatedBy
    ): void {
        $statement = \db()->prepare(
            'UPDATE hr_departments
             SET code = :code,
                 name = :name,
                 description = :description,
                 active = :active,
                 updated_by = :updated_by
             WHERE company_id = :company_id
               AND department_id = :department_id
               AND deleted_at IS NULL'
        );
        $statement->execute([
            'company_id' => $companyId,
            'code' => $code,
            'name' => $name,
            'description' =>
                $description === ''
                    ? null
                    : $description,
            'active' => $active ? 1 : 0,
            'updated_by' => $updatedBy,
            'department_id' => $departmentId,
        ]);
    }
}
