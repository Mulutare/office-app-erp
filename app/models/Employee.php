<?php

declare(strict_types=1);

namespace App\Models;

final class Employee
{
    public function employeeNumberExists(
        int $companyId,
        string $employeeNumber,
        ?int $ignoreEmployeeId = null
    ): bool {
        return $this->uniqueValueExists(
            $companyId,
            'employee_number',
            $employeeNumber,
            $ignoreEmployeeId
        );
    }

    public function workEmailExists(
        int $companyId,
        string $workEmail,
        ?int $ignoreEmployeeId = null
    ): bool {
        return $this->uniqueValueExists(
            $companyId,
            'work_email',
            $workEmail,
            $ignoreEmployeeId
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function managerOptions(
        int $companyId,
        ?int $excludeEmployeeId = null,
        ?int $includeManagerId = null
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
             WHERE company_id = :company_id
               AND deleted_at IS NULL
               AND (
                   employment_status <> \'terminated\'
                   OR employee_id =
                        :include_manager_id
               )
               AND (
                   :exclude_employee_null IS NULL
                   OR employee_id
                        <> :exclude_employee_value
               )
             ORDER BY last_name, first_name
             LIMIT 250'
        );
        $statement->execute([
            'company_id' => $companyId,
            'exclude_employee_null' =>
                $excludeEmployeeId,
            'exclude_employee_value' =>
                $excludeEmployeeId,
            'include_manager_id' =>
                $includeManagerId ?? 0,
        ]);
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
    public function availableUserOptions(
        int $companyId,
        ?int $currentEmployeeId = null
    ): array {
        $statement = \db()->prepare(
            'SELECT
                users.user_id,
                users.username,
                users.display_name,
                users.email,
                users.active
             FROM company_users memberships
             INNER JOIN users
                 ON users.user_id = memberships.user_id
             LEFT JOIN hr_employees employees
                 ON employees.user_id = users.user_id
                AND employees.company_id = :employee_company_id
                AND employees.deleted_at IS NULL
                AND (
                    :current_employee_null IS NULL
                    OR employees.employee_id
                        <> :current_employee_value
                )
             WHERE (
                   users.active = TRUE
                   OR users.user_id = (
                       SELECT current_employee.user_id
                       FROM hr_employees current_employee
                       WHERE current_employee.employee_id =
                            :current_user_employee_id
                         AND current_employee.company_id =
                            :current_employee_company_id
                         AND current_employee.deleted_at
                            IS NULL
                   )
               )
               AND memberships.company_id =
                    :membership_company_id
               AND memberships.active = TRUE
               AND users.deleted_at IS NULL
               AND employees.employee_id IS NULL
             ORDER BY
                users.display_name,
                users.username
             LIMIT 250'
        );
        $statement->execute([
            'employee_company_id' => $companyId,
            'current_employee_company_id' =>
                $companyId,
            'membership_company_id' => $companyId,
            'current_employee_null' =>
                $currentEmployeeId,
            'current_employee_value' =>
                $currentEmployeeId,
            'current_user_employee_id' =>
                $currentEmployeeId ?? 0,
        ]);
        $users = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($users) ? $users : [];
    }

    public function managerExists(
        int $companyId,
        int $employeeId
    ): bool
    {
        $statement = \db()->prepare(
            'SELECT 1
             FROM hr_employees
             WHERE company_id = :company_id
               AND employee_id = :employee_id
               AND deleted_at IS NULL
               AND employment_status
                    <> \'terminated\'
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function availableUserExists(
        int $companyId,
        int $userId,
        ?int $currentEmployeeId = null
    ): bool {
        $statement = \db()->prepare(
            'SELECT 1
             FROM company_users memberships
             INNER JOIN users
                 ON users.user_id = memberships.user_id
             LEFT JOIN hr_employees employees
                 ON employees.user_id = users.user_id
                AND employees.company_id = :employee_company_id
                AND employees.deleted_at IS NULL
                AND (
                    :current_employee_null IS NULL
                    OR employees.employee_id
                        <> :current_employee_value
                )
             WHERE users.user_id = :user_id
               AND (
                   users.active = TRUE
                   OR users.user_id = (
                       SELECT current_employee.user_id
                       FROM hr_employees current_employee
                       WHERE current_employee.employee_id =
                            :current_user_employee_id
                         AND current_employee.company_id =
                            :current_employee_company_id
                         AND current_employee.deleted_at
                            IS NULL
                   )
               )
               AND memberships.company_id =
                    :membership_company_id
               AND memberships.active = TRUE
               AND users.deleted_at IS NULL
               AND employees.employee_id IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'employee_company_id' => $companyId,
            'current_employee_company_id' =>
                $companyId,
            'membership_company_id' => $companyId,
            'user_id' => $userId,
            'current_employee_null' =>
                $currentEmployeeId,
            'current_employee_value' =>
                $currentEmployeeId,
            'current_user_employee_id' =>
                $currentEmployeeId ?? 0,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function create(
        int $companyId,
        array $values,
        int $createdBy
    ): int {
        $statement = \db()->prepare(
            'INSERT INTO hr_employees
                (
                    company_id,
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
                    :company_id,
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
            'company_id' => $companyId,
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
     * @param array<string, mixed> $values
     */
    public function update(
        int $companyId,
        int $employeeId,
        array $values,
        int $updatedBy
    ): void {
        $statement = \db()->prepare(
            'UPDATE hr_employees
             SET employee_number = :employee_number,
                 user_id = :user_id,
                 first_name = :first_name,
                 middle_name = :middle_name,
                 last_name = :last_name,
                 preferred_name = :preferred_name,
                 work_email = :work_email,
                 work_phone = :work_phone,
                 department_id = :department_id,
                 job_title = :job_title,
                 employment_type =
                    :employment_type,
                 employment_status =
                    :employment_status,
                 hire_date = :hire_date,
                 termination_date =
                    :termination_date,
                 manager_employee_id =
                    :manager_employee_id,
                 updated_by = :updated_by
             WHERE company_id = :company_id
               AND employee_id = :employee_id
               AND deleted_at IS NULL'
        );
        $statement->execute([
            'company_id' => $companyId,
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
            'updated_by' => $updatedBy,
            'employee_id' => $employeeId,
        ]);
    }

    public function wouldCreateManagerCycle(
        int $companyId,
        int $employeeId,
        int $managerEmployeeId
    ): bool {
        $currentId = $managerEmployeeId;
        $visited = [];
        $statement = \db()->prepare(
            'SELECT manager_employee_id
             FROM hr_employees
             WHERE company_id = :company_id
               AND employee_id = :employee_id
               AND deleted_at IS NULL
             LIMIT 1'
        );

        while ($currentId > 0) {
            if ($currentId === $employeeId) {
                return true;
            }

            if (isset($visited[$currentId])) {
                return true;
            }

            $visited[$currentId] = true;
            $statement->execute([
                'company_id' => $companyId,
                'employee_id' => $currentId,
            ]);
            $managerId = $statement->fetchColumn();

            if (
                $managerId === false
                || $managerId === null
            ) {
                return false;
            }

            $currentId = (int) $managerId;

            if (count($visited) >= 250) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(
        int $companyId,
        array $filters
    ): int
    {
        $query = $this->whereClause(
            $companyId,
            $filters
        );
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
        int $companyId,
        array $filters,
        int $limit,
        int $offset
    ): array {
        $query = $this->whereClause(
            $companyId,
            $filters
        );
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
                AND departments.company_id =
                    employees.company_id
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
    public function find(
        int $companyId,
        int $employeeId
    ): ?array
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
                AND departments.company_id =
                    employees.company_id
             LEFT JOIN users
                 ON users.user_id = employees.user_id
             LEFT JOIN hr_employees manager
                 ON manager.employee_id =
                    employees.manager_employee_id
                AND manager.company_id =
                    employees.company_id
                AND manager.deleted_at IS NULL
             WHERE employees.employee_id =
                :employee_id
               AND employees.company_id = :company_id
               AND employees.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
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
        int $companyId,
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
             WHERE company_id = :company_id
               AND manager_employee_id =
                :manager_employee_id
               AND deleted_at IS NULL
             ORDER BY last_name, first_name
             LIMIT :limit'
        );
        $statement->bindValue(
            ':company_id',
            $companyId,
            \PDO::PARAM_INT
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
    public function statusSummary(int $companyId): array
    {
        $statement = \db()->prepare(
            'SELECT
                employment_status,
                COUNT(*) AS total
             FROM hr_employees
             WHERE company_id = :company_id
               AND deleted_at IS NULL
             GROUP BY employment_status'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
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
    private function whereClause(
        int $companyId,
        array $filters
    ): array
    {
        $conditions = [
            'employees.company_id = :company_id',
            'employees.deleted_at IS NULL',
        ];
        $parameters = [
            'company_id' => $companyId,
        ];
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
        int $companyId,
        string $column,
        string $value,
        ?int $ignoreEmployeeId = null
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
             WHERE company_id = :company_id
               AND ' . $column . ' = :value
               AND deleted_at IS NULL
               AND (
                   :ignore_employee_null IS NULL
                   OR employee_id
                        <> :ignore_employee_value
               )
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'value' => $value,
            'ignore_employee_null' =>
                $ignoreEmployeeId,
            'ignore_employee_value' =>
                $ignoreEmployeeId,
        ]);

        return $statement->fetchColumn() !== false;
    }
}
