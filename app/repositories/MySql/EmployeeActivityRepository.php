<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

class EmployeeActivityRepository extends MySqlRepository
{
    public function countForEmployee(
        int $companyId,
        int $employeeId
    ): int {
        $statement = $this->connection()->prepare(
            'SELECT COUNT(*)
             FROM audit_logs
             WHERE company_id = :company_id
               AND table_name = :table_name
               AND record_id = :record_id'
        );
        $statement->execute([
            'company_id' => $companyId,
            'table_name' => 'hr_employees',
            'record_id' => (string) $employeeId,
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pageForEmployee(
        int $companyId,
        int $employeeId,
        int $limit,
        int $offset
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                audit_logs.audit_log_id,
                audit_logs.action,
                audit_logs.module,
                audit_logs.old_values,
                audit_logs.new_values,
                audit_logs.ip_address,
                audit_logs.user_agent,
                audit_logs.created_at,
                actor.display_name AS actor_name,
                actor.username AS actor_username
             FROM audit_logs
             LEFT JOIN users actor
                 ON actor.user_id = audit_logs.user_id
             WHERE audit_logs.company_id =
                    :company_id
               AND audit_logs.table_name =
                    :table_name
               AND audit_logs.record_id = :record_id
             ORDER BY
                audit_logs.created_at DESC,
                audit_logs.audit_log_id DESC
             LIMIT :limit
             OFFSET :offset'
        );
        $statement->bindValue(
            ':company_id',
            $companyId,
            \PDO::PARAM_INT
        );
        $statement->bindValue(
            ':table_name',
            'hr_employees',
            \PDO::PARAM_STR
        );
        $statement->bindValue(
            ':record_id',
            (string) $employeeId,
            \PDO::PARAM_STR
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
        $events = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($events) ? $events : [];
    }
}
