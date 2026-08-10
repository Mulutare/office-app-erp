<?php

declare(strict_types=1);

namespace App\Services\DataExchange;

use PDO;
use RuntimeException;

final class ExternalIdService
{
    public function __construct(private ?PDO $connection = null)
    {
        $this->connection ??= \db();
    }

    public function resolve(int $companyId, string $entityType, string $externalId): ?int
    {
        $statement = $this->connection->prepare('SELECT entity_id FROM data_external_ids WHERE company_id=:company AND entity_type=:type AND external_id=:external LIMIT 1');
        $statement->execute(['company'=>$companyId,'type'=>$this->type($entityType),'external'=>$this->id($externalId)]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function assign(int $companyId, string $entityType, int $entityId, string $externalId): void
    {
        $existing = $this->resolve($companyId, $entityType, $externalId);
        if ($existing !== null && $existing !== $entityId) {
            throw new RuntimeException('External ID already belongs to another record in this company.');
        }
        $statement = $this->connection->prepare('INSERT INTO data_external_ids(company_id,entity_type,entity_id,external_id) VALUES(:company,:type,:entity,:external) ON DUPLICATE KEY UPDATE external_id=VALUES(external_id)');
        $statement->execute(['company'=>$companyId,'type'=>$this->type($entityType),'entity'=>$entityId,'external'=>$this->id($externalId)]);
    }

    public function ensure(int $companyId, string $entityType, int $entityId): string
    {
        $statement = $this->connection->prepare('SELECT external_id FROM data_external_ids WHERE company_id=:company AND entity_type=:type AND entity_id=:entity LIMIT 1');
        $statement->execute(['company'=>$companyId,'type'=>$this->type($entityType),'entity'=>$entityId]);
        $current = $statement->fetchColumn();
        if (is_string($current)) return $current;
        $external = sprintf('%s_%d_%d', str_replace('-', '_', $this->type($entityType)), $companyId, $entityId);
        $this->assign($companyId, $entityType, $entityId, $external);
        return $external;
    }

    private function type(string $value): string{if(preg_match('/^[a-z][a-z0-9-]{1,79}$/',$value)!==1)throw new RuntimeException('Invalid External ID entity type.');return $value;}
    private function id(string $value): string{$value=trim($value);if($value===''||strlen($value)>190||preg_match('/^[A-Za-z0-9_.:-]+$/',$value)!==1)throw new RuntimeException('External ID contains unsupported characters.');return $value;}
}
