<?php

declare(strict_types=1);

namespace App\Repositories\Oracle;

use App\Repositories\AuditLogWriter;
use App\Services\TenantContext;

final class AuditLogRepository extends OracleRepository
    implements AuditLogWriter
{
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
