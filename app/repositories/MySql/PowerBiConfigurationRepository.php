<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use PDO;

final class PowerBiConfigurationRepository extends MySqlRepository
{
    public function findForCompany(int $companyId): ?array
    {
        $statement = $this->connection()->prepare(
            "SELECT
                company_id,
                enabled,
                authentication_mode,
                microsoft_tenant_id,
                workspace_id,
                report_id,
                dataset_id,
                report_name,
                client_id,
                credential_reference,
                configuration_status,
                last_successful_validation_at,
                updated_by,
                created_at,
                updated_at,
                (
                    client_secret_ciphertext IS NOT NULL
                    AND client_secret_ciphertext <> ''
                ) AS secret_configured
             FROM company_power_bi_configurations
             WHERE company_id = :company_id"
        );
        $statement->execute(['company_id' => $companyId]);
        $configuration = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($configuration) ? $configuration : null;
    }

    public function secretCiphertext(int $companyId): ?string
    {
        $statement = $this->connection()->prepare(
            'SELECT client_secret_ciphertext
             FROM company_power_bi_configurations
             WHERE company_id = :company_id'
        );
        $statement->execute(['company_id' => $companyId]);
        $ciphertext = $statement->fetchColumn();

        return is_string($ciphertext) && $ciphertext !== ''
            ? $ciphertext
            : null;
    }

    public function save(
        int $companyId,
        array $values,
        ?string $ciphertext,
        int $actorId
    ): void {
        $sql = 'INSERT INTO company_power_bi_configurations (
                    company_id,
                    enabled,
                    authentication_mode,
                    microsoft_tenant_id,
                    workspace_id,
                    report_id,
                    dataset_id,
                    report_name,
                    client_id,
                    client_secret_ciphertext,
                    credential_reference,
                    configuration_status,
                    last_successful_validation_at,
                    updated_by
                ) VALUES (
                    :company_id,
                    :enabled,
                    :mode,
                    :tenant,
                    :workspace,
                    :report,
                    :dataset,
                    :name,
                    :client,
                    :secret,
                    :reference,
                    :status,
                    :validated,
                    :actor
                )
                ON DUPLICATE KEY UPDATE
                    enabled = VALUES(enabled),
                    authentication_mode = VALUES(authentication_mode),
                    microsoft_tenant_id = VALUES(microsoft_tenant_id),
                    workspace_id = VALUES(workspace_id),
                    report_id = VALUES(report_id),
                    dataset_id = VALUES(dataset_id),
                    report_name = VALUES(report_name),
                    client_id = VALUES(client_id),
                    client_secret_ciphertext = COALESCE(
                        VALUES(client_secret_ciphertext),
                        client_secret_ciphertext
                    ),
                    credential_reference = VALUES(credential_reference),
                    configuration_status = VALUES(configuration_status),
                    last_successful_validation_at =
                        VALUES(last_successful_validation_at),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()';
        $this->connection()->prepare($sql)->execute([
            'company_id' => $companyId,
            'enabled' => $values['enabled'] ? 1 : 0,
            'mode' => $values['authentication_mode'],
            'tenant' => $values['microsoft_tenant_id'],
            'workspace' => $values['workspace_id'],
            'report' => $values['report_id'],
            'dataset' => $values['dataset_id'],
            'name' => $values['report_name'],
            'client' => $values['client_id'],
            'secret' => $ciphertext,
            'reference' => $values['credential_reference'],
            'status' => $values['configuration_status'],
            'validated' => $values['last_successful_validation_at'],
            'actor' => $actorId,
        ]);
    }

    public function markValidation(
        int $companyId,
        string $status,
        int $actorId
    ): void {
        $statement = $this->connection()->prepare(
            "UPDATE company_power_bi_configurations
             SET configuration_status = :status,
                 last_successful_validation_at = IF(
                    :ready_status = 'ready',
                    NOW(),
                    last_successful_validation_at
                 ),
                 updated_by = :actor,
                 updated_at = NOW()
             WHERE company_id = :company_id"
        );
        $statement->execute([
            'status' => $status,
            'ready_status' => $status,
            'actor' => $actorId,
            'company_id' => $companyId,
        ]);
    }
}
