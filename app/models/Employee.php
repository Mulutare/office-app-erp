<?php

declare(strict_types=1);

namespace App\Models;

final class Employee
{
    public function employeeNumberExists(
        string $employeeNumber
    ): bool {
        return $this->uniqueValueExists(
            'employee_number',
            $employeeNumber
        );
    }

    public function workEmailExists(
        string $workEmail
    ): bool {
        return $this->uniqueValueExists(
            'work_email',
            $workEmail
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function managerOptions(): array
    {
        $statement = \db()->query(
            'SELECT
                employee_id,
                employee_number,
                first_name,
                last_name,
                preferred_name,
                job_title
             FROM hr_employees
             WHERE deleted_at IS NULL
               AND employment_status
                    <> \'terminated\'
             ORDER BY last_name, first_name
             LIMIT 250'
        );
        $employees = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($employees)
            ? $employees
            : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availableUserOptions(): array
    {
        $statement = \db()->query(
            'SELECT
                users.user_id,
                users.username,
                users.display_name,
                users.email
             FROM users
             LEFT JOIN hr_employees employees
                 ON employees.user_id = users.user_id
                AND employees.deleted_at IS NULL
             WHERE users.active = TRUE
               AND users.deleted_at IS NULL
               AND employees.employee_id IS NULL
             ORDER BY
                users.display_name,
                users.username
             LIMIT 250'
        );
        $users = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($users) ? $users : [];
    }

    public function managerExists(int $employeeId): bool
    {
        $statement = \db()->prepare(
            'SELECT 1
             FROM hr_employees
             WHERE employee_id = :employee_id
               AND deleted_at IS NULL
               AND employment_status
                    <> \'terminated\'
             LIMIT 1'
        );
        $statement->execute([
            'employee_id' => $employeeId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function availableUserExists(int $userId): bool
    {
        $statement = \db()->prepare(
            'SELECT 1
             FROM users
             LEFT JOIN hr_employees employees
                 ON employees.user_id = users.user_id
                AND employees.deleted_at IS NULL
             WHERE users.user_id = :user_id
               AND users.active = TRUE
               AND users.deleted_at IS NULL
               AND employees.employee_id IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function create(
        array $values,
        int $createdBy
    ): int {
        $statement = \db()->prepare(
            'INSERT INTO hr_employees
                (
                    employee_number,
                    user_id,
                    first_name,
                    middle_name,
                    last_name,
                    preferred_name,
                    work_email,
                    work_phone,
                    department_id,
                    job_title,
                    employment_type,
                    employment_status,
                    hire_date,
                    termination_date,
                    manager_employee_id,
                    created_by,
                    updated_by
                )
             VALUES
                (
                    :employee_number,
                    :user_id,
                    :first_name,
                    :middle_name,
                    :last_name,
                    :preferred_name,
                    :work_email,
                    :work_phone,
                    :department_id,
                    :job_title,
                    :employment_type,
                    :employment_status,
                    :hire_date,
                    :termination_date,
                    :manager_employee_id,
                    :created_by,
                    :updated_by
                )'
        );
        $statement->execute([
            'employee_number' =>
                $values['employee_number'],
            'user_id' => $values['user_id'],
            'first_name' => $values['first_name'],
            'middle_name' => $values['middle_name'],
            'last_name' => $values['last_name'],
            'preferred_name' =>
                $values['preferred_name'],
            'work_email' => $values['work_email'],
            'work_phone' => $values['work_phone'],
            'department_id' =>
                $values['department_id'],
            'job_title' => $values['job_title'],
            'employment_type' =>
                $values['employment_type'],
            'employment_status' =>
                $values['employment_status'],
            'hire_date' => $values['hire_date'],
            'termination_date' =>
                $values['termination_date'],
            'manager_employee_id' =>
                $values['manager_employee_id'],
            'created_by' => $createdBy,
            'updated_by' => $createdBy,
        ]);

        return (int) \db()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters): int
    {
        $query = $this->whereClause($filters);
        $statement = \db()->prepare(
            'SELECT COUNT(*)
             FROM hr_employees employees
             LEFT JOIN hr_departments departments
                 ON departments.department_id =
                    employees.department_id
             WHERE ' . $query['sql']
        );
        $statement->execute($query['parameters']);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<array<string, mixed>>
     */
    public function page(
        array $filters,
        int $limit,
        int $offset
    ): array {
        $query = $this->whereClause($filters);
        $statement = \db()->prepare(
            'SELECT
                employees.employee_id,
                employees.employee_number,
                employees.first_name,
                employees.middle_name,
                employees.last_name,
                employees.preferred_name,
                employees.work_email,
                employees.work_phone,
                employees.job_title,
                employees.employment_type,
                employees.employment_status,
                employees.hire_date,
                employees.user_id,
                departments.department_id,
                departments.code AS department_code,
                departments.name AS department_name,
                users.username,
                users.active AS account_active
             FROM hr_employees employees
             LEFT JOIN hr_departments departments
                 ON departments.department_id =
                    employees.department_id
             LEFT JOIN users
                 ON users.user_id = employees.user_id
             WHERE ' . $query['sql'] . '
             ORDER BY
                employees.last_name,
                employees.first_name,
                employees.employee_id
             LIMIT :limit
             OFFSET :offset'
        );

        $this->bindParameters(
            $statement,
            $query['parameters']
        );
        $statement->bindValue(
            ':limit',
            max(1, min($limit, 100)),
            \PDO::PARAM_INT
        );
        $statement->bindValue(
            ':offset',
            max(0, $offset),
            \PDO::PARAM_INT
        );
        $statement->execute();
        $employees = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($employees)
            ? $employees
            : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $employeeId): ?array
    {
        $statement = \db()->prepare(
            'SELECT
                employees.*,
                departments.code AS department_code,
                departments.name AS department_name,
                departments.description
                    AS department_description,
                users.username,
                users.display_name
                    AS account_display_name,
                users.active AS account_active,
                users.last_login_at
                    AS account_last_login_at,
                CONCAT_WS(
                    \' \',
                    manager.first_name,
                    manager.last_name
                ) AS manager_name,
                manager.employee_number
                    AS manager_employee_number
             FROM hr_employees employees
             LEFT JOIN hr_departments departments
                 ON departments.department_id =
                    employees.department_id
             LEFT JOIN users
                 ON users.user_id = employees.user_id
             LEFT JOIN hr_employees manager
                 ON manager.employee_id =
                    employees.manager_employee_id
                AND manager.deleted_at IS NULL
             WHERE employees.employee_id =
                :employee_id
               AND employees.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'employee_id' => $employeeId,
        ]);
        $employee = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($employee)
            ? $employee
            : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function directReports(
        int $managerEmployeeId,
        int $limit = 20
    ): array {
        $statement = \db()->prepare(
            'SELECT
                employee_id,
                employee_number,
                first_name,
                last_name,
                preferred_name,
                job_title,
                employment_status
             FROM hr_employees
             WHERE manager_employee_id =
                :manager_employee_id
               AND deleted_at IS NULL
             ORDER BY last_name, first_name
             LIMIT :limit'
        );
        $statement->bindValue(
            ':manager_employee_id',
            $managerEmployeeId,
            \PDO::PARAM_INT
        );
        $statement->bindValue(
            ':limit',
            max(1, min($limit, 100)),
            \PDO::PARAM_INT
        );
        $statement->execute();
        $reports = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($reports) ? $reports : [];
    }

    /**
     * @return array<string, int>
     */
    public function statusSummary(): array
    {
        $statement = \db()->query(
            'SELECT
                employment_status,
                COUNT(*) AS total
             FROM hr_employees
             WHERE deleted_at IS NULL
             GROUP BY employment_status'
        );
        $rows = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );
        $summary = [];

        foreach ($rows as $row) {
            $summary[(string) (
                $row['employment_status'] ?? ''
            )] = (int) ($row['total'] ?? 0);
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{
     *     sql: string,
     *     parameters: array<string, int|string>
     * }
     */
    private function whereClause(array $filters): array
    {
        $conditions = [
            'employees.deleted_at IS NULL',
        ];
        $parameters = [];
        $search = (string) (
            $filters['search'] ?? ''
        );

        if ($search !== '') {
            $conditions[] = '(
                employees.employee_number
                    LIKE :search_number
                OR employees.first_name
                    LIKE :search_first_name
                OR employees.middle_name
                    LIKE :search_middle_name
                OR employees.last_name
                    LIKE :search_last_name
                OR employees.preferred_name
                    LIKE :search_preferred_name
                OR employees.work_email
                    LIKE :search_email
                OR employees.job_title
                    LIKE :search_job_title
            )';
            $value = '%' . $search . '%';
            $parameters['search_number'] = $value;
            $parameters['search_first_name'] =
                $value;
            $parameters['search_middle_name'] =
                $value;
            $parameters['search_last_name'] =
                $value;
            $parameters['search_preferred_name'] =
                $value;
            $parameters['search_email'] = $value;
            $parameters['search_job_title'] =
                $value;
        }

        $status = (string) (
            $filters['status'] ?? ''
        );

        if ($status !== '') {
            $conditions[] =
                'employees.employment_status = :status';
            $parameters['status'] = $status;
        }

        $departmentId = (int) (
            $filters['departmentId'] ?? 0
        );

        if ($departmentId > 0) {
            $conditions[] =
                'employees.department_id =
                    :department_id';
            $parameters['department_id'] =
                $departmentId;
        }

        return [
            'sql' => implode(' AND ', $conditions),
            'parameters' => $parameters,
        ];
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function bindParameters(
        \PDOStatement $statement,
        array $parameters
    ): void {
        foreach ($parameters as $key => $value) {
            $statement->bindValue(
                ':' . $key,
                $value,
                is_int($value)
                    ? \PDO::PARAM_INT
                    : \PDO::PARAM_STR
            );
        }
    }

    private function uniqueValueExists(
        string $column,
        string $value
    ): bool {
        $allowedColumns = [
            'employee_number',
            'work_email',
        ];

        if (!in_array(
            $column,
            $allowedColumns,
            true
        )) {
            throw new \InvalidArgumentException(
                'Unsupported employee uniqueness column.'
            );
        }

        $statement = \db()->prepare(
            'SELECT 1
             FROM hr_employees
             WHERE ' . $column . ' = :value
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'value' => $value,
        ]);

        return $statement->fetchColumn() !== false;
    }
}
