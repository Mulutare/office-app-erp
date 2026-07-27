<?php

declare(strict_types=1);

namespace App\Models;

final class UserActivity
{
    public function countForUser(
        int $userId,
        string $type
    ): int {
        $query = $this->queryDefinition(
            $userId,
            $type,
            true
        );

        $statement = \db()->prepare(
            'SELECT COUNT(*)
             FROM (' . implode(
                ' UNION ALL ',
                $query['parts']
            ) . ') user_activity'
        );
        $statement->execute($query['parameters']);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pageForUser(
        int $userId,
        string $type,
        int $limit,
        int $offset
    ): array {
        $query = $this->queryDefinition(
            $userId,
            $type,
            false
        );

        $statement = \db()->prepare(
            'SELECT *
             FROM (' . implode(
                ' UNION ALL ',
                $query['parts']
            ) . ') user_activity
             ORDER BY occurred_at DESC, event_key DESC
             LIMIT :limit
             OFFSET :offset'
        );

        foreach (
            $query['parameters'] as $key => $value
        ) {
            $statement->bindValue(
                ':' . $key,
                $value,
                is_int($value)
                    ? \PDO::PARAM_INT
                    : \PDO::PARAM_STR
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

        $events = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($events) ? $events : [];
    }

    /**
     * @return array{
     *     parts: list<string>,
     *     parameters: array<string, int|string>
     * }
     */
    private function queryDefinition(
        int $userId,
        string $type,
        bool $countOnly
    ): array {
        $allowedTypes = [
            'all',
            'authentication',
            'administration',
        ];

        if (!in_array($type, $allowedTypes, true)) {
            $type = 'all';
        }

        $parts = [];
        $parameters = [];

        if ($type !== 'administration') {
            $parameters['login_user_id'] = $userId;
            $parts[] = $countOnly
                ? $this->loginCountQuery()
                : $this->loginPageQuery();
        }

        $parameters['audit_actor_user_id'] =
            $userId;
        $parameters['audit_record_id'] =
            (string) $userId;
        $parts[] = $countOnly
            ? $this->auditCountQuery($type)
            : $this->auditPageQuery($type);

        return [
            'parts' => $parts,
            'parameters' => $parameters,
        ];
    }

    private function loginCountQuery(): string
    {
        return
            'SELECT login_attempt_id AS event_id
             FROM login_attempts
             WHERE user_id = :login_user_id';
    }

    private function auditCountQuery(
        string $type
    ): string {
        return
            'SELECT audit_log_id AS event_id
             FROM audit_logs
             WHERE (
                    user_id = :audit_actor_user_id
                    OR (
                        table_name = \'users\'
                        AND record_id =
                            :audit_record_id
                    )
                )
               AND action <> \'LOGIN\''
            . $this->auditTypeCondition($type);
    }

    private function loginPageQuery(): string
    {
        return
            'SELECT
                CONCAT(
                    \'login-\',
                    login_attempt_id
                ) COLLATE utf8mb4_unicode_ci
                    AS event_key,
                \'login_attempt\'
                    COLLATE utf8mb4_unicode_ci
                    AS source,
                CASE
                    WHEN successful = TRUE
                        THEN \'LOGIN_SUCCESS\'
                    ELSE \'LOGIN_FAILED\'
                END COLLATE utf8mb4_unicode_ci
                    AS action,
                \'authentication\'
                    COLLATE utf8mb4_unicode_ci
                    AS category,
                successful,
                failure_reason
                    COLLATE utf8mb4_unicode_ci
                    AS failure_reason,
                NULL AS actor_name,
                username_entered
                    COLLATE utf8mb4_unicode_ci
                    AS actor_username,
                ip_address
                    COLLATE utf8mb4_unicode_ci
                    AS ip_address,
                user_agent
                    COLLATE utf8mb4_unicode_ci
                    AS user_agent,
                \'users\'
                    COLLATE utf8mb4_unicode_ci
                    AS target_table,
                CAST(user_id AS CHAR)
                    COLLATE utf8mb4_unicode_ci
                    AS target_id,
                NULL AS old_values,
                NULL AS new_values,
                attempted_at AS occurred_at
             FROM login_attempts
             WHERE user_id = :login_user_id';
    }

    private function auditPageQuery(
        string $type
    ): string {
        return
            'SELECT
                CONCAT(
                    \'audit-\',
                    audit_logs.audit_log_id
                ) COLLATE utf8mb4_unicode_ci
                    AS event_key,
                \'audit\'
                    COLLATE utf8mb4_unicode_ci
                    AS source,
                audit_logs.action
                    COLLATE utf8mb4_unicode_ci
                    AS action,
                audit_logs.module
                    COLLATE utf8mb4_unicode_ci
                    AS category,
                NULL AS successful,
                NULL AS failure_reason,
                actor.display_name
                    COLLATE utf8mb4_unicode_ci
                    AS actor_name,
                actor.username
                    COLLATE utf8mb4_unicode_ci
                    AS actor_username,
                audit_logs.ip_address
                    COLLATE utf8mb4_unicode_ci
                    AS ip_address,
                audit_logs.user_agent
                    COLLATE utf8mb4_unicode_ci
                    AS user_agent,
                audit_logs.table_name
                    COLLATE utf8mb4_unicode_ci
                    AS target_table,
                audit_logs.record_id
                    COLLATE utf8mb4_unicode_ci
                    AS target_id,
                audit_logs.old_values
                    COLLATE utf8mb4_unicode_ci
                    AS old_values,
                audit_logs.new_values
                    COLLATE utf8mb4_unicode_ci
                    AS new_values,
                audit_logs.created_at AS occurred_at
             FROM audit_logs
             LEFT JOIN users actor
                 ON actor.user_id = audit_logs.user_id
             WHERE (
                    audit_logs.user_id =
                        :audit_actor_user_id
                    OR (
                        audit_logs.table_name = \'users\'
                        AND audit_logs.record_id =
                            :audit_record_id
                    )
                )
               AND audit_logs.action <> \'LOGIN\''
            . $this->auditTypeCondition($type);
    }

    private function auditTypeCondition(
        string $type
    ): string {
        if ($type === 'authentication') {
            return
                ' AND audit_logs.module =
                    \'authentication\'';
        }

        if ($type === 'administration') {
            return
                ' AND audit_logs.module =
                    \'administration\'';
        }

        return '';
    }
}
