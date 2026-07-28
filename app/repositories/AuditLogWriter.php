<?php

declare(strict_types=1);

namespace App\Repositories;

interface AuditLogWriter
{
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
    ): void;
}
