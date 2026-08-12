<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CompanyModule;
use Throwable;

final class CompanyModuleService
{
    private CompanyModule $modules;
    private AuditLog $auditLogs;

    public function __construct()
    {
        $this->modules = new CompanyModule();
        $this->auditLogs = new AuditLog();
    }

    /**
     * @return array<string, mixed>
     */
    public function company(
        ?int $companyId = null
    ): array
    {
        if ($companyId === null) {
            $sessionCompanyId =
                $_SESSION['auth']['company'][
                    'company_id'
                ] ?? null;

            $companyId = is_int($sessionCompanyId)
                ? $sessionCompanyId
                : null;
        }

        $company = $companyId !== null
            ? $this->modules->companyById($companyId)
            : $this->modules->companyByCode(
                (string) \config(
                    'company_code',
                    'default'
                )
            );

        if (
            $company === null
            || empty($company['active'])
        ) {
            throw new \RuntimeException(
                'The configured company is unavailable.'
            );
        }

        return $company;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enabledNavigationModules(
        ?int $companyId = null
    ): array
    {
        $company = $this->company($companyId);

        return $this->modules->enabledForCompany(
            (int) $company['company_id']
        );
    }

    /**
     * @return list<string>
     */
    public function enabledCodes(): array
    {
        return array_values(array_map(
            static fn (array $module): string =>
                (string) $module['code'],
            $this->enabledNavigationModules()
        ));
    }

    public function isEnabled(string $code): bool
    {
        return in_array(
            $code,
            $this->enabledCodes(),
            true
        );
    }

    /**
     * @return array{
     *     company: array<string, mixed>,
     *     modules: list<array<string, mixed>>
     * }
     */
    public function catalog(): array
    {
        $company = $this->company();
        $modules = $this->modules->catalogForCompany(
            (int) $company['company_id']
        );
        $now = time();

        foreach ($modules as &$module) {
            $released = ($module['release_status'] ?? 'roadmap') === 'released';
            $licenseStatus = (string) (
                $module['license_status']
                ?? 'not_licensed'
            );
            $expiresAt = $module['expires_at']
                ?? null;
            $expiryTimestamp = is_string($expiresAt)
                ? strtotime($expiresAt)
                : false;
            $licenseCurrent = in_array(
                $licenseStatus,
                ['active', 'trial'],
                true
            ) && (
                $expiryTimestamp === false
                || $expiryTimestamp > $now
            );

            $module['canToggle'] =
                $released && $licenseCurrent;
            $module['isEnabled'] =
                $module['canToggle']
                && !empty($module['enabled']);
            $module['isEffective'] =
                $module['isEnabled']
                && !empty($module['dependencies_satisfied']);
            $module['availabilityLabel'] =
                $released
                    ? 'Released'
                    : 'Roadmap';
            $module['licenseLabel'] =
                $this->licenseLabel(
                    $licenseStatus
                );
            $module['availabilityTone'] =
                $released
                    ? 'success'
                    : 'muted';
            $module['licenseTone'] =
                $licenseCurrent
                    ? 'success'
                    : 'muted';
            $module['companyLabel'] = $module['isEnabled']
                ? 'Enabled'
                : 'Disabled';
            $module['stateExplanation'] = match (true) {
                !$released => 'Requires platform release and licensing',
                !$licenseCurrent => 'Module is released but is not licensed for this company',
                empty($module['enabled']) => 'Module is licensed but disabled for this company',
                empty($module['dependencies_satisfied']) => 'Required module dependencies are not enabled',
                default => 'Available to authorized users',
            };
        }

        unset($module);

        return [
            'company' => $company,
            'modules' => $modules,
        ];
    }

    /**
     * @param mixed $selectedCodes
     *
     * @return array{
     *     successful: bool,
     *     changed: bool,
     *     errors: array<string, string>
     * }
     */
    public function updateEnabledModules(
        mixed $selectedCodes,
        int $updatedBy
    ): array {
        $selected = $this->normalizeCodes(
            $selectedCodes
        );
        $company = $this->company();
        $companyId = (int) $company['company_id'];
        $toggleable = $this->modules
            ->toggleableForCompany($companyId);
        $allowedCodes = array_map(
            static fn (array $module): string =>
                (string) $module['code'],
            $toggleable
        );
        $invalid = array_diff(
            $selected,
            $allowedCodes
        );

        if ($invalid !== []) {
            return [
                'successful' => false,
                'changed' => false,
                'errors' => [
                    'modules' =>
                        'One or more selected modules cannot be enabled.',
                ],
            ];
        }

        $catalog = $this->modules->catalogForCompany($companyId);
        $dependencyErrors = [];
        foreach ($catalog as $module) {
            $code = (string) ($module['code'] ?? '');
            if (!in_array($code, $selected, true)) {
                continue;
            }
            $required = array_values(array_filter(explode(',', (string) ($module['required_dependency_codes'] ?? ''))));
            $missing = array_diff($required, $selected);
            if ($missing !== []) {
                $dependencyErrors[] = $code . ' requires ' . implode(', ', $missing);
            }
        }
        if ($dependencyErrors !== []) {
            return [
                'successful' => false,
                'changed' => false,
                'errors' => ['modules' => implode('. ', $dependencyErrors) . '.'],
            ];
        }

        $before = [];

        foreach ($toggleable as $module) {
            if (!empty($module['enabled'])) {
                $before[] = (string) $module['code'];
            }
        }

        sort($before);
        sort($selected);
        $changed = $before !== $selected;

        if (!$changed) {
            return [
                'successful' => true,
                'changed' => false,
                'errors' => [],
            ];
        }

        try {
            \db()->beginTransaction();

            foreach ($toggleable as $module) {
                $code = (string) $module['code'];
                $this->modules->setEnabled(
                    $companyId,
                    (int) $module['module_id'],
                    in_array(
                        $code,
                        $selected,
                        true
                    ),
                    $updatedBy
                );
            }

            $this->auditLogs->record(
                $updatedBy,
                'UPDATE_MODULE_ENTITLEMENTS',
                'administration',
                'company_modules',
                (string) $companyId,
                ['enabled_modules' => $before],
                ['enabled_modules' => $selected]
            );

            \db()->commit();
        } catch (Throwable $exception) {
            if (\db()->inTransaction()) {
                \db()->rollBack();
            }

            throw $exception;
        }

        return [
            'successful' => true,
            'changed' => true,
            'errors' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeCodes(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $codes = [];

        foreach ($value as $code) {
            if (!is_string($code)) {
                continue;
            }

            $code = trim($code);

            if (
                $code !== ''
                && preg_match(
                    '/^[a-z][a-z0-9_]{1,49}$/',
                    $code
                )
            ) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    private function licenseLabel(
        string $status
    ): string {
        return match ($status) {
            'active' => 'Active license',
            'trial' => 'Trial license',
            'suspended' => 'License suspended',
            'expired' => 'License expired',
            default => 'Not licensed',
        };
    }
}
