<?php

declare(strict_types=1);

namespace App\Models;

final class Department
{
    /**
     * @return list<array<string, mixed>>
     */
    public function activeOptions(): array
    {
        $statement = \db()->query(
            'SELECT
                department_id,
                code,
                name
             FROM hr_departments
             WHERE active = TRUE
               AND deleted_at IS NULL
             ORDER BY name'
        );
        $departments = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($departments)
            ? $departments
            : [];
    }

    public function codeExists(
        string $code,
        ?int $ignoreDepartmentId = null
    ): bool {
        $statement = \db()->prepare(
            'SELECT 1
             FROM hr_departments
             WHERE code = :code
               AND deleted_at IS NULL
               AND (
                   :ignore_department_null IS NULL
                   OR department_id
                        <> :ignore_department_value
               )
             LIMIT 1'
        );
        $statement->execute([
            'code' => $code,
            'ignore_department_null' =>
                $ignoreDepartmentId,
            'ignore_department_value' =>
                $ignoreDepartmentId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function nameExists(
        string $name,
        ?int $ignoreDepartmentId = null
    ): bool {
        $statement = \db()->prepare(
            'SELECT 1
             FROM hr_departments
             WHERE name = :name
               AND deleted_at IS NULL
               AND (
                   :ignore_department_null IS NULL
                   OR department_id
                        <> :ignore_department_value
               )
             LIMIT 1'
        );
        $statement->execute([
            'name' => $name,
            'ignore_department_null' =>
                $ignoreDepartmentId,
            'ignore_department_value' =>
                $ignoreDepartmentId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function activeExists(
        int $departmentId
    ): bool {
        $statement = \db()->prepare(
            'SELECT 1
             FROM hr_departments
             WHERE department_id = :department_id
               AND active = TRUE
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'department_id' => $departmentId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function create(
        string $code,
        string $name,
        string $description,
        bool $active,
        int $createdBy
    ): int {
        $statement = \db()->prepare(
            'INSERT INTO hr_departments
                (
                    code,
                    name,
                    description,
                    active,
                    created_by,
                    updated_by
                )
             VALUES
                (
                    :code,
                    :name,
                    :description,
                    :active,
                    :created_by,
                    :updated_by
                )'
        );
        $statement->execute([
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
    public function managementList(): array
    {
        $statement = \db()->query(
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
                AND employees.deleted_at IS NULL
             WHERE departments.deleted_at IS NULL
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
             WHERE department_id = :department_id
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
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
        int $departmentId
    ): int {
        $statement = \db()->prepare(
            'SELECT COUNT(*)
             FROM hr_employees
             WHERE department_id = :department_id
               AND employment_status <> \'terminated\'
               AND deleted_at IS NULL'
        );
        $statement->execute([
            'department_id' => $departmentId,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function update(
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
             WHERE department_id = :department_id
               AND deleted_at IS NULL'
        );
        $statement->execute([
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
