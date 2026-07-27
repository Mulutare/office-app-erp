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
}
