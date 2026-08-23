<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Repositories\MySql\PowerBiConfigurationRepository;

final class PowerBiConfigurationService
{
    private PowerBiConfigurationRepository $repository;
    private PowerBiSecretCipher $cipher;
    private AuditLog $audit;

    public function __construct()
    {
        $this->repository = new PowerBiConfigurationRepository();
        $this->cipher = new PowerBiSecretCipher();
        $this->audit = new AuditLog();
    }

    public function configuration(): ?array
    {
        return $this->repository->findForCompany(
            (new TenantContext())->companyId()
        );
    }

    public function pageState(): array
    {
        $configuration = $this->configuration();

        if ($configuration === null || empty($configuration['enabled'])) {
            return [
                'state' => 'enabled_not_configured',
                'configuration' => $configuration,
                'embedUrl' => null,
            ];
        }

        if (($configuration['configuration_status'] ?? '') !== 'ready') {
            return [
                'state' => 'configuration_invalid',
                'configuration' => $configuration,
                'embedUrl' => null,
            ];
        }

        if (($configuration['authentication_mode'] ?? '') !== 'user_owns_data') {
            return [
                'state' => 'configuration_invalid',
                'configuration' => $configuration,
                'embedUrl' => null,
            ];
        }

        $embedUrl = 'https://app.powerbi.com/reportEmbed?'
            . http_build_query([
                'reportId' => $configuration['report_id'],
                'autoAuth' => 'true',
                'ctid' => $configuration['microsoft_tenant_id'],
            ], '', '&', PHP_QUERY_RFC3986);

        return [
            'state' => 'ready',
            'configuration' => $configuration,
            'embedUrl' => $embedUrl,
        ];
    }

    public function save(array $input, int $actorId): array
    {
        $companyId = (new TenantContext())->companyId();
        $mode = trim((string) ($input['authentication_mode'] ?? ''));
        $values = [
            'enabled' => !empty($input['enabled']),
            'authentication_mode' => $mode,
            'microsoft_tenant_id' => trim(
                (string) ($input['microsoft_tenant_id'] ?? '')
            ),
            'workspace_id' => $this->nullable($input['workspace_id'] ?? null),
            'report_id' => trim((string) ($input['report_id'] ?? '')),
            'dataset_id' => $this->nullable($input['dataset_id'] ?? null),
            'report_name' => trim((string) ($input['report_name'] ?? '')),
            'client_id' => $this->nullable($input['client_id'] ?? null),
            'credential_reference' => $this->nullable(
                $input['credential_reference'] ?? null
            ),
            'configuration_status' => 'configuration_invalid',
            'last_successful_validation_at' => null,
        ];
        $secret = trim((string) ($input['client_secret'] ?? ''));
        $existingSecretConfigured = $this->repository
            ->secretCiphertext($companyId) !== null;
        $errors = $this->validate(
            $values,
            false,
            $secret !== '' || $existingSecretConfigured
        );

        if ($errors !== []) {
            return ['successful' => false, 'errors' => $errors];
        }

        $ciphertext = $secret !== ''
            ? $this->cipher->encrypt($secret)
            : null;
        $this->repository->save(
            $companyId,
            $values,
            $ciphertext,
            $actorId
        );
        $this->audit->record(
            $actorId,
            'UPDATE_POWER_BI_CONFIGURATION',
            'analytics',
            'company_power_bi_configurations',
            (string) $companyId,
            null,
            [
                'authentication_mode' => $mode,
                'report_id' => $values['report_id'],
                'secret_configured' => $secret !== '' || $existingSecretConfigured,
            ],
            $companyId
        );

        return ['successful' => true, 'errors' => []];
    }

    public function validateConfiguration(int $actorId): array
    {
        $companyId = (new TenantContext())->companyId();
        $configuration = $this->repository->findForCompany($companyId);

        if ($configuration === null) {
            return [
                'successful' => false,
                'errors' => [
                    'configuration' => 'Save the Power BI configuration before validating it.',
                ],
            ];
        }

        $errors = $this->validate(
            $configuration,
            true,
            !empty($configuration['secret_configured'])
        );
        $status = $errors === [] ? 'ready' : 'configuration_invalid';
        $this->repository->markValidation($companyId, $status, $actorId);
        $this->audit->record(
            $actorId,
            'VALIDATE_POWER_BI_CONFIGURATION',
            'analytics',
            'company_power_bi_configurations',
            (string) $companyId,
            null,
            ['status' => $status],
            $companyId
        );

        return ['successful' => $errors === [], 'errors' => $errors];
    }

    private function validate(
        array $values,
        bool $validateReadiness,
        bool $secretConfigured
    ): array {
        $errors = [];
        $modes = ['user_owns_data', 'platform_managed', 'company_managed'];

        if (!in_array($values['authentication_mode'], $modes, true)) {
            $errors['authentication_mode'] = 'Select a supported authentication mode.';
        }

        foreach ([
            'microsoft_tenant_id' => 'Microsoft tenant ID',
            'report_id' => 'Report ID',
        ] as $field => $label) {
            if (!$this->uuid((string) $values[$field])) {
                $errors[$field] = "$label must be a valid UUID.";
            }
        }

        foreach ([
            'workspace_id' => 'Workspace ID',
            'dataset_id' => 'Dataset ID',
            'client_id' => 'Application / client ID',
        ] as $field => $label) {
            if (
                $values[$field] !== null
                && !$this->uuid((string) $values[$field])
            ) {
                $errors[$field] = "$label must be a valid UUID.";
            }
        }

        if (
            $values['report_name'] === ''
            || strlen($values['report_name']) > 190
        ) {
            $errors['report_name'] =
                'Report display name is required and must not exceed 190 characters.';
        }

        if (
            $values['authentication_mode'] === 'company_managed'
            && ($values['client_id'] === null || !$secretConfigured)
        ) {
            $errors['client_secret'] =
                'Company-managed authentication requires a client ID and configured client secret.';
        }

        if (
            $validateReadiness
            && $values['authentication_mode'] !== 'user_owns_data'
        ) {
            $errors['connection'] =
                'Service-principal embed token validation requires the production Microsoft connector.';
        }

        return $errors;
    }

    private function uuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
