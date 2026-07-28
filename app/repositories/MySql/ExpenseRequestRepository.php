<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

class ExpenseRequestRepository extends MySqlRepository
{
    /**
     * @return array<string, int>
     */
    public function statusSummary(int $companyId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT
                COUNT(*) AS total,
                SUM(
                    status = \'draft\'
                ) AS draft,
                SUM(
                    status = \'submitted\'
                ) AS submitted,
                SUM(
                    status = \'approved\'
                ) AS approved,
                SUM(
                    status = \'rejected\'
                ) AS rejected,
                SUM(
                    status = \'paid\'
                ) AS paid,
                SUM(
                    status = \'cancelled\'
                ) AS cancelled
             FROM finance_expense_requests
             WHERE company_id = :company_id
               AND deleted_at IS NULL'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
        $summary = $statement->fetch(
            \PDO::FETCH_ASSOC
        );
        $result = [];

        foreach (
            [
                'total',
                'draft',
                'submitted',
                'approved',
                'rejected',
                'paid',
                'cancelled',
            ] as $key
        ) {
            $result[$key] = (int) (
                $summary[$key] ?? 0
            );
        }

        return $result;
    }

    /**
     * @param array<string, string> $filters
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
        $statement = $this->connection()->prepare(
            'SELECT COUNT(*)
             FROM finance_expense_requests requests
             INNER JOIN hr_employees employees
                 ON employees.employee_id =
                    requests.requested_by_employee_id
                AND employees.company_id =
                    requests.company_id
             LEFT JOIN finance_expense_categories
                    categories
                 ON categories.category_id =
                    requests.category_id
                AND categories.company_id =
                    requests.company_id
             WHERE ' . $query['sql']
        );
        $statement->execute($query['parameters']);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, string> $filters
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
        $statement = $this->connection()->prepare(
            'SELECT
                requests.expense_request_id,
                requests.request_number,
                requests.title,
                requests.amount,
                requests.currency,
                requests.expense_date,
                requests.status,
                requests.submitted_at,
                requests.reviewed_at,
                requests.paid_at,
                requests.created_at,
                employees.employee_id,
                employees.employee_number,
                employees.first_name,
                employees.last_name,
                employees.preferred_name,
                categories.category_id,
                categories.code AS category_code,
                categories.name AS category_name
             FROM finance_expense_requests requests
             INNER JOIN hr_employees employees
                 ON employees.employee_id =
                    requests.requested_by_employee_id
                AND employees.company_id =
                    requests.company_id
             LEFT JOIN finance_expense_categories
                    categories
                 ON categories.category_id =
                    requests.category_id
                AND categories.company_id =
                    requests.company_id
             WHERE ' . $query['sql'] . '
             ORDER BY
                requests.created_at DESC,
                requests.expense_request_id DESC
             LIMIT :limit
             OFFSET :offset'
        );

        foreach (
            $query['parameters'] as $key => $value
        ) {
            $statement->bindValue(
                ':' . $key,
                $value,
                \PDO::PARAM_STR
            );
        }

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
        $requests = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($requests)
            ? $requests
            : [];
    }

    /**
     * @param array<string, string> $filters
     *
     * @return array{
     *     sql: string,
     *     parameters: array<string, string>
     * }
     */
    private function whereClause(
        int $companyId,
        array $filters
    ): array
    {
        $conditions = [
            'requests.company_id = :company_id',
            'requests.deleted_at IS NULL',
            'employees.deleted_at IS NULL',
        ];
        $parameters = [
            'company_id' => $companyId,
        ];
        $search = trim((string) (
            $filters['search'] ?? ''
        ));
        $status = trim((string) (
            $filters['status'] ?? ''
        ));

        if ($search !== '') {
            $conditions[] = '(
                requests.request_number
                    LIKE :search_request
                OR requests.title
                    LIKE :search_title
                OR employees.employee_number
                    LIKE :search_employee_number
                OR employees.first_name
                    LIKE :search_first_name
                OR employees.last_name
                    LIKE :search_last_name
                OR employees.preferred_name
                    LIKE :search_preferred_name
                OR categories.name
                    LIKE :search_category
            )';
            $searchValue = '%' . $search . '%';
            $parameters = [
                'search_request' => $searchValue,
                'search_title' => $searchValue,
                'search_employee_number' =>
                    $searchValue,
                'search_first_name' =>
                    $searchValue,
                'search_last_name' =>
                    $searchValue,
                'search_preferred_name' =>
                    $searchValue,
                'search_category' => $searchValue,
            ];
        }

        if ($status !== '') {
            $conditions[] =
                'requests.status = :status';
            $parameters['status'] = $status;
        }

        return [
            'sql' => implode(
                ' AND ',
                $conditions
            ),
            'parameters' => $parameters,
        ];
    }
}
