<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Services\TenantContext;

class AuditLogRepository extends MySqlRepository
{
    /**
     * Return recent activity performed by or targeting a user.
     *
     * @return list<array<string, mixed>>
     */
    public function recentForUser(
        int $userId,
        int $companyId,
        int $limit = 10
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                audit_logs.audit_log_id,
                audit_logs.action,
                audit_logs.module,
                audit_logs.table_name,
                audit_logs.record_id,
                audit_logs.ip_address,
                audit_logs.created_at,
                actor.display_name AS actor_name,
                actor.username AS actor_username
             FROM audit_logs
             LEFT JOIN users actor
                 ON actor.user_id = audit_logs.user_id
             WHERE audit_logs.company_id = :company_id
               AND (
                    audit_logs.user_id = :actor_user_id
                    OR (
                        audit_logs.table_name = :table_name
                        AND audit_logs.record_id = :record_id
                    )
               )
             ORDER BY audit_logs.created_at DESC
             LIMIT :limit'
        );

        $statement->bindValue(
            ':company_id',
            $companyId,
            \PDO::PARAM_INT
        );
        $statement->bindValue(
            ':actor_user_id',
            $userId,
            \PDO::PARAM_INT
        );
        $statement->bindValue(
            ':table_name',
            'users',
            \PDO::PARAM_STR
        );
        $statement->bindValue(
            ':record_id',
            (string) $userId,
            \PDO::PARAM_STR
        );
        $statement->bindValue(
            ':limit',
            max(1, min($limit, 50)),
            \PDO::PARAM_INT
        );
        $statement->execute();

        $activity = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($activity) ? $activity : [];
    }

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    public function record(
        ?int $userId,
        string $action,
        string $module,
        ?string $tableName = null,
        ?string $recordId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $companyId = null
    ): void {
        $companyId = $companyId
            ?? (new TenantContext())->companyIdOrNull();
        $statement = $this->connection()->prepare(
            'INSERT INTO audit_logs
                (
                    company_id,
                    user_id,
                    action,
                    module,
                    table_name,
                    record_id,
                    old_values,
                    new_values,
                    ip_address,
                    user_agent
                )
             VALUES
                (
                    :company_id,
                    :user_id,
                    :action,
                    :module,
                    :table_name,
                    :record_id,
                    :old_values,
                    :new_values,
                    :ip_address,
                    :user_agent
                )'
        );

        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
            'action' => $action,
            'module' => $module,
            'table_name' => $tableName,
            'record_id' => $recordId,
            'old_values' => $this->encode($oldValues),
            'new_values' => $this->encode($newValues),
            'ip_address' => \requestIp(),
            'user_agent' => \requestUserAgent(),
        ]);
    }

    /**
     * @param array<string, mixed>|null $values
     */
    private function encode(?array $values): ?string
    {
        if ($values === null) {
            return null;
        }

        $json = json_encode(
            $values,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );

        return is_string($json) ? $json : null;
    }
}
