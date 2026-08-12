<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Models\Role;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Updates vendor-owned company and commercial settings.
 *
 * Lifecycle transitions deliberately live in CompanyLifecycleService so a
 * routine profile edit cannot silently suspend or reactivate a customer.
 */
final class CompanyUpdateService
{
    private Company $companies;
    private AuditLog $auditLogs;
    private User $users;
    private Role $roles;

    public function __construct()
    {
        $this->companies = new Company();
        $this->auditLogs = new AuditLog();
        $this->users = new User();
        $this->roles = new Role();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     successful: bool,
     *     notFound: bool,
     *     changed: bool,
     *     errors: array<string, string>
     * }
     */
    public function update(
        int $companyId,
        array $input,
        int $updatedBy
    ): array {
        if (!$this->isPlatformAdministrator(
            $updatedBy
        )) {
            return [
                'successful' => false,
                'notFound' => false,
                'changed' => false,
                'errors' => [
                    'form' =>
                        'Only a platform administrator can update customer companies.',
                ],
            ];
        }

        $company = $this->normalize($input);
        $selectedCodes = $this->normalizeCodes(
            $input['module_codes'] ?? []
        );
        $catalog = $this->companies
            ->provisioningModules();
        $availableCodes = array_values(array_map(
            static fn (array $module): string =>
                (string) $module['code'],
            array_filter(
                $catalog,
                static fn (array $module): bool =>
                    ($module['release_status'] ?? 'roadmap') === 'released'
            )
        ));
        $errors = $this->validate(
            $company,
            $selectedCodes,
            $availableCodes
        );

        if ($companyId < 1) {
            return [
                'successful' => false,
                'notFound' => true,
                'changed' => false,
                'errors' => [],
            ];
        }

        if ($errors !== []) {
            return [
                'successful' => false,
                'notFound' => false,
                'changed' => false,
                'errors' => $errors,
            ];
        }

        try {
            \db()->beginTransaction();
            $existing = $this->companies
                ->lockForAdministration($companyId);

            if ($existing === null) {
                \db()->rollBack();

                return [
                    'successful' => false,
                    'notFound' => true,
                    'changed' => false,
                    'errors' => [],
                ];
            }

            $beforeModules = $this->enabledModuleCodes(
                $this->companies
                    ->modulesForCompany($companyId)
            );
            $beforeCommercialStatus = (
                (string) $existing[
                    'subscription_status'
                ] === 'suspended'
            )
                ? $this->companies
                    ->preferredResumeStatus(
                        $companyId
                    )
                : (string) $existing[
                    'subscription_status'
                ];
            $storedStatus = (
                (string) $existing[
                    'subscription_status'
                ] === 'suspended'
            )
                ? 'suspended'
                : $company[
                    'subscription_status'
                ];
            $after = $company;
            $after['subscription_status'] =
                $storedStatus;
            $before = $this->profileSnapshot(
                $existing
            );
            $afterSnapshot = $this->profileSnapshot(
                $after
            );
            sort($beforeModules);
            sort($selectedCodes);
            $changed = $before !== $afterSnapshot
                || $beforeModules !== $selectedCodes
                || $beforeCommercialStatus
                    !== $company[
                        'subscription_status'
                    ];

            if (!$changed) {
                $this->roles->copyPermissionTemplatesToCompany($companyId, $updatedBy);
                \db()->commit();

                return [
                    'successful' => true,
                    'notFound' => false,
                    'changed' => false,
                    'errors' => [],
                ];
            }

            $this->companies
                ->updateAdministrationProfile(
                    $companyId,
                    $after
                );

            foreach ($catalog as $module) {
                $code = (string) (
                    $module['code'] ?? ''
                );
                $enabled = ($module['release_status'] ?? 'roadmap') === 'released' && in_array(
                    $code,
                    $selectedCodes,
                    true
                );
                $this->companies
                    ->updateModuleEntitlement(
                        $companyId,
                        (int) $module['module_id'],
                        $enabled,
                        (string) $company[
                            'subscription_status'
                        ],
                        $company[
                            'subscription_expires_at'
                        ],
                        $updatedBy
                    );
            }
            // Re-apply reviewed permission templates whenever licensing is
            // changed. This covers modules enabled after company creation.
            $this->roles->copyPermissionTemplatesToCompany($companyId, $updatedBy);

            $this->auditLogs->record(
                $updatedBy,
                'UPDATE_COMPANY',
                'administration',
                'companies',
                (string) $companyId,
                $before + [
                    'module_codes' =>
                        $beforeModules,
                    'commercial_status' =>
                        $beforeCommercialStatus,
                ],
                $afterSnapshot + [
                    'module_codes' =>
                        $selectedCodes,
                    'commercial_status' =>
                        $company[
                            'subscription_status'
                        ],
                ],
                $companyId
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
            'notFound' => false,
            'changed' => true,
            'errors' => [],
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $expiresAt = trim((string) (
            $input['subscription_expires_at']
            ?? ''
        ));
        $expiryValid = true;

        if ($expiresAt !== '') {
            $date = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $expiresAt
            );
            $dateErrors =
                DateTimeImmutable::getLastErrors();
            $expiryValid = $date !== false
                && (
                    $dateErrors === false
                    || (
                        $dateErrors['warning_count'] === 0
                        && $dateErrors['error_count'] === 0
                    )
                )
                && $date->format('Y-m-d')
                    === $expiresAt;
            $expiresAt = $expiryValid
                ? $date->format(
                    'Y-m-d 23:59:59'
                )
                : $expiresAt;
        } else {
            $expiresAt = null;
        }

        return [
            'name' => trim((string) (
                $input['name'] ?? ''
            )),
            'legal_name' => $this->nullable(
                $input['legal_name'] ?? null
            ),
            'contact_email' => strtolower(
                (string) $this->nullable(
                    $input['contact_email'] ?? null
                )
            ) ?: null,
            'contact_phone' => $this->nullable(
                $input['contact_phone'] ?? null
            ),
            'country_code' => strtoupper(trim(
                (string) (
                    $input['country_code'] ?? ''
                )
            )),
            'default_currency' => strtoupper(trim(
                (string) (
                    $input['default_currency'] ?? ''
                )
            )),
            'timezone' => trim((string) (
                $input['timezone'] ?? ''
            )),
            'subscription_status' =>
                strtolower(trim((string) (
                    $input['subscription_status']
                    ?? ''
                ))),
            'subscription_expires_at' =>
                $expiresAt,
            'subscription_expiry_valid' =>
                $expiryValid,
            'brand_primary_color' => strtoupper(
                trim((string) (
                    $input['brand_primary_color']
                    ?? ''
                ))
            ),
        ];
    }

    /**
     * @param array<string, mixed> $company
     * @param list<string> $selectedCodes
     * @param list<string> $availableCodes
     *
     * @return array<string, string>
     */
    private function validate(
        array $company,
        array $selectedCodes,
        array $availableCodes
    ): array {
        $errors = [];
        $nameLength = mb_strlen(
            (string) $company['name']
        );

        if ($nameLength < 2 || $nameLength > 150) {
            $errors['name'] =
                'Company name must contain 2-150 characters.';
        }

        if (
            $company['legal_name'] !== null
            && mb_strlen(
                (string) $company['legal_name']
            ) > 190
        ) {
            $errors['legal_name'] =
                'Legal name cannot exceed 190 characters.';
        }

        $email = $company['contact_email'];

        if (
            $email !== null
            && (
                strlen((string) $email) > 190
                || !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            )
        ) {
            $errors['contact_email'] =
                'Enter a valid contact email address.';
        }

        $phone = $company['contact_phone'];

        if (
            $phone !== null
            && (
                mb_strlen((string) $phone) < 5
                || mb_strlen((string) $phone) > 40
            )
        ) {
            $errors['contact_phone'] =
                'Contact phone must contain 5-40 characters.';
        }

        if (!preg_match(
            '/^[A-Z]{2}$/',
            (string) $company['country_code']
        )) {
            $errors['country_code'] =
                'Country code must use two letters.';
        }

        if (!preg_match(
            '/^[A-Z]{3}$/',
            (string) $company['default_currency']
        )) {
            $errors['default_currency'] =
                'Currency must use a three-letter ISO code.';
        }

        if (!in_array(
            $company['timezone'],
            DateTimeZone::listIdentifiers(),
            true
        )) {
            $errors['timezone'] =
                'Select a valid timezone.';
        }

        if (!in_array(
            $company['subscription_status'],
            ['active', 'trial'],
            true
        )) {
            $errors['subscription_status'] =
                'Select Active or Trial.';
        }

        $expiresAt = $company[
            'subscription_expires_at'
        ];

        if (empty(
            $company['subscription_expiry_valid']
        )) {
            $errors['subscription_expires_at'] =
                'Enter a valid expiry date.';
        } elseif (
            $company['subscription_status'] === 'trial'
            && $expiresAt === null
        ) {
            $errors['subscription_expires_at'] =
                'A trial must have an expiry date.';
        } elseif ($expiresAt !== null) {
            $timestamp = strtotime(
                (string) $expiresAt
            );

            if (
                $timestamp === false
                || $timestamp <= time()
            ) {
                $errors['subscription_expires_at'] =
                    'Expiry date must be in the future.';
            }
        }

        if (!preg_match(
            '/^#[0-9A-F]{6}$/',
            (string) $company[
                'brand_primary_color'
            ]
        )) {
            $errors['brand_primary_color'] =
                'Enter a valid six-digit brand color.';
        }

        if ($selectedCodes === []) {
            $errors['modules'] =
                'Select at least one released module.';
        } elseif (
            array_diff(
                $selectedCodes,
                $availableCodes
            ) !== []
        ) {
            $errors['modules'] =
                'One or more selected modules are not available for licensing.';
        }

        return $errors;
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private function normalizeCodes(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $codes = [];

        foreach ($value as $code) {
            if (
                !is_string($code)
                || preg_match(
                    '/^[a-z][a-z0-9_]{1,49}$/',
                    $code
                ) !== 1
            ) {
                continue;
            }

            $codes[] = $code;
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param list<array<string, mixed>> $modules
     *
     * @return list<string>
     */
    private function enabledModuleCodes(
        array $modules
    ): array {
        return array_values(array_map(
            static fn (array $module): string =>
                (string) $module['code'],
            array_filter(
                $modules,
                static fn (array $module): bool =>
                    !empty($module['enabled'])
                    && in_array(
                        (string) (
                            $module['license_status']
                            ?? ''
                        ),
                        ['active', 'trial'],
                        true
                    )
            )
        ));
    }

    /**
     * @param array<string, mixed> $company
     *
     * @return array<string, mixed>
     */
    private function profileSnapshot(
        array $company
    ): array {
        return [
            'name' => (string) (
                $company['name'] ?? ''
            ),
            'legal_name' =>
                $company['legal_name'] ?? null,
            'contact_email' =>
                $company['contact_email'] ?? null,
            'contact_phone' =>
                $company['contact_phone'] ?? null,
            'country_code' => (string) (
                $company['country_code'] ?? ''
            ),
            'default_currency' => (string) (
                $company['default_currency'] ?? ''
            ),
            'timezone' => (string) (
                $company['timezone'] ?? ''
            ),
            'subscription_status' => (string) (
                $company['subscription_status'] ?? ''
            ),
            'subscription_expires_at' =>
                $company[
                    'subscription_expires_at'
                ] ?? null,
            'brand_primary_color' => (string) (
                $company['brand_primary_color']
                ?? ''
            ),
        ];
    }

    private function nullable(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function isPlatformAdministrator(
        int $userId
    ): bool {
        if ($userId < 1) {
            return false;
        }

        $user = $this->users->findById($userId);

        return is_array($user)
            && !empty($user['active'])
            && !empty($user['is_platform_admin']);
    }
}
