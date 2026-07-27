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

    public function codeExists(string $code): bool
    {
        $statement = \db()->prepare(
            'SELECT 1
             FROM hr_departments
             WHERE code = :code
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'code' => $code,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function nameExists(string $name): bool
    {
        $statement = \db()->prepare(
            'SELECT 1
             FROM hr_departments
             WHERE name = :name
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'name' => $name,
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
}
