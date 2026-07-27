<?php

declare(strict_types=1);

namespace App\Models;

final class AuditLogQuery
{
    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters): int
    {
        $query = $this->whereClause($filters);
        $statement = \db()->prepare(
            'SELECT COUNT(*)
             FROM audit_logs
             LEFT JOIN users actor
                 ON actor.user_id = audit_logs.user_id
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
                audit_logs.audit_log_id,
                audit_logs.action,
                audit_logs.module,
                audit_logs.table_name,
                audit_logs.record_id,
                audit_logs.ip_address,
                audit_logs.created_at,
                actor.user_id AS actor_user_id,
                actor.display_name AS actor_name,
                actor.username AS actor_username
             FROM audit_logs
             LEFT JOIN users actor
                 ON actor.user_id = audit_logs.user_id
             WHERE ' . $query['sql'] . '
             ORDER BY
                audit_logs.created_at DESC,
                audit_logs.audit_log_id DESC
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

        $logs = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($logs) ? $logs : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $auditLogId): ?array
    {
        $statement = \db()->prepare(
            'SELECT
                audit_logs.audit_log_id,
                audit_logs.user_id,
                audit_logs.action,
                audit_logs.module,
                audit_logs.table_name,
                audit_logs.record_id,
                audit_logs.old_values,
                audit_logs.new_values,
                audit_logs.ip_address,
                audit_logs.user_agent,
                audit_logs.created_at,
                actor.display_name AS actor_name,
                actor.username AS actor_username,
                actor.email AS actor_email
             FROM audit_logs
             LEFT JOIN users actor
                 ON actor.user_id = audit_logs.user_id
             WHERE audit_logs.audit_log_id =
                :audit_log_id
             LIMIT 1'
        );
        $statement->execute([
            'audit_log_id' => $auditLogId,
        ]);
        $log = $statement->fetch(
            \PDO::FETCH_ASSOC
        );

        return is_array($log) ? $log : null;
    }

    /**
     * @return array{
     *     modules: list<string>,
     *     actions: list<string>,
     *     actors: list<array<string, mixed>>
     * }
     */
    public function filterOptions(): array
    {
        $modules = \db()->query(
            'SELECT DISTINCT module
             FROM audit_logs
             WHERE module <> \'\'
             ORDER BY module'
        )->fetchAll(\PDO::FETCH_COLUMN);

        $actions = \db()->query(
            'SELECT DISTINCT action
             FROM audit_logs
             WHERE action <> \'\'
             ORDER BY action'
        )->fetchAll(\PDO::FETCH_COLUMN);

        $actors = \db()->query(
            'SELECT DISTINCT
                users.user_id,
                users.display_name,
                users.username
             FROM audit_logs
             INNER JOIN users
                 ON users.user_id = audit_logs.user_id
             ORDER BY users.display_name, users.username'
        )->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'modules' => array_values(array_map(
                'strval',
                is_array($modules) ? $modules : []
            )),
            'actions' => array_values(array_map(
                'strval',
                is_array($actions) ? $actions : []
            )),
            'actors' => is_array($actors)
                ? $actors
                : [],
        ];
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
        array $filters
    ): array {
        $conditions = ['1 = 1'];
        $parameters = [];
        $search = (string) (
            $filters['search'] ?? ''
        );

        if ($search !== '') {
            $conditions[] = '(
                audit_logs.action LIKE :search_action
                OR audit_logs.module LIKE :search_module
                OR audit_logs.table_name LIKE :search_table
                OR audit_logs.record_id LIKE :search_record
                OR audit_logs.ip_address LIKE :search_ip
                OR actor.display_name LIKE :search_actor_name
                OR actor.username LIKE :search_actor_username
            )';
            $searchValue = '%' . $search . '%';
            $parameters['search_action'] =
                $searchValue;
            $parameters['search_module'] =
                $searchValue;
            $parameters['search_table'] =
                $searchValue;
            $parameters['search_record'] =
                $searchValue;
            $parameters['search_ip'] =
                $searchValue;
            $parameters['search_actor_name'] =
                $searchValue;
            $parameters['search_actor_username'] =
                $searchValue;
        }

        $module = (string) (
            $filters['module'] ?? ''
        );

        if ($module !== '') {
            $conditions[] =
                'audit_logs.module = :module';
            $parameters['module'] = $module;
        }

        $action = (string) (
            $filters['action'] ?? ''
        );

        if ($action !== '') {
            $conditions[] =
                'audit_logs.action = :action';
            $parameters['action'] = $action;
        }

        $actor = (string) (
            $filters['actor'] ?? ''
        );

        if ($actor === 'system') {
            $conditions[] =
                'audit_logs.user_id IS NULL';
        } elseif (
            $actor !== ''
            && ctype_digit($actor)
        ) {
            $conditions[] =
                'audit_logs.user_id = :actor_user_id';
            $parameters['actor_user_id'] =
                (int) $actor;
        }

        $dateFrom = (string) (
            $filters['dateFromSql'] ?? ''
        );

        if ($dateFrom !== '') {
            $conditions[] =
                'audit_logs.created_at >= :date_from';
            $parameters['date_from'] = $dateFrom;
        }

        $dateTo = (string) (
            $filters['dateToExclusiveSql'] ?? ''
        );

        if ($dateTo !== '') {
            $conditions[] =
                'audit_logs.created_at < :date_to';
            $parameters['date_to'] = $dateTo;
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
}
