<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\Role;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;
use Throwable;

final class CompanyProvisioningService
{
    private const PAGE_SIZE = 20;

    private Company $companies;
    private CompanyMembership $memberships;
    private Role $roles;
    private User $users;
    private AuditLog $auditLogs;
    private TemporaryPasswordGenerator $passwords;

    public function __construct()
    {
        $this->companies = new Company();
        $this->memberships =
            new CompanyMembership();
        $this->roles = new Role();
        $this->users = new User();
        $this->auditLogs = new AuditLog();
        $this->passwords =
            new TemporaryPasswordGenerator();
    }

    /**
     * @return array<string, mixed>
     */
    public function listing(
        string $search,
        string $status,
        int $page
    ): array {
        $search = mb_substr(
            trim($search),
            0,
            120
        );
        $allowedStatuses = [
            'all',
            'pending',
            'active',
            'trial',
            'expired',
            'suspended',
            'inactive',
        ];

        if (!in_array(
            $status,
            $allowedStatuses,
            true
        )) {
            $status = 'all';
        }

        $page = max(1, $page);
        $total = $this->companies
            ->administrationCount(
                $search,
                $status
            );
        $lastPage = max(
            1,
            (int) ceil(
                $total / self::PAGE_SIZE
            )
        );
        $page = min($page, $lastPage);
        $offset =
            ($page - 1) * self::PAGE_SIZE;
        $companies = $this->companies
            ->administrationPage(
                $search,
                $status,
                self::PAGE_SIZE,
                $offset
            );

        foreach ($companies as &$company) {
            $company += $this->statusPresentation(
                $company
            );
        }

        unset($company);

        return [
            'companies' => $companies,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
            'pagination' => [
                'page' => $page,
                'lastPage' => $lastPage,
                'pageSize' => self::PAGE_SIZE,
                'total' => $total,
                'from' => $total === 0
                    ? 0
                    : $offset + 1,
                'to' => min(
                    $offset + self::PAGE_SIZE,
                    $total
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formOptions(): array
    {
        $modules = $this->companies
            ->provisioningModules();

        foreach ($modules as &$module) {
            $module['canLicense'] =
                !empty($module['available']);
        }

        unset($module);

        $timezones = [];

        foreach (
            DateTimeZone::listIdentifiers()
            as $timezone
        ) {
            $timezones[$timezone] = str_replace(
                '_',
                ' ',
                $timezone
            );
        }

        return [
            'modules' => $modules,
            'timezones' => $timezones,
            'currencies' => [
                'KES' => 'KES — Kenyan Shilling',
                'USD' => 'USD — US Dollar',
                'EUR' => 'EUR — Euro',
                'GBP' => 'GBP — Pound Sterling',
                'ETB' => 'ETB — Ethiopian Birr',
                'UGX' => 'UGX — Ugandan Shilling',
                'TZS' => 'TZS — Tanzanian Shilling',
                'RWF' => 'RWF — Rwandan Franc',
                'ZAR' => 'ZAR — South African Rand',
                'AED' => 'AED — UAE Dirham',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     successful: bool,
     *     errors: array<string, string>,
     *     companyId?: int
     * }
     */
    public function create(
        array $input,
        int $provisionedBy
    ): array {
        $company = $this->normalizeCompany(
            $input
        );
        $owner = $this->normalizeOwner($input);
        $selectedCodes = $this->normalizeCodes(
            $input['module_codes'] ?? []
        );
        $catalog = $this->companies
            ->provisioningModules();
        $availableCodes = array_values(
            array_map(
                static fn (array $module): string =>
                    (string) $module['code'],
                array_filter(
                    $catalog,
                    static fn (array $module): bool =>
                        !empty($module['available'])
                )
            )
        );
        $errors = $this->validate(
            $company,
            $selectedCodes,
            $availableCodes
        );
        $errors += $this->validateOwner($owner);

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
            ];
        }

        $licenseStatus =
            $company['subscription_status']
            === 'trial'
                ? 'trial'
                : 'active';
        $temporaryPassword =
            $this->passwords->generate();
        $passwordHash = password_hash(
            $temporaryPassword,
            PASSWORD_DEFAULT
        );

        if (!is_string($passwordHash)) {
            throw new \RuntimeException(
                'Unable to securely hash the owner password.'
            );
        }

        try {
            \db()->beginTransaction();

            $companyId = $this->companies
                ->create(
                    $company,
                    $provisionedBy
                );
            $this->companies->provisionModules(
                $companyId,
                $catalog,
                $selectedCodes,
                $licenseStatus,
                $company[
                    'subscription_expires_at'
                ],
                $provisionedBy
            );
            $this->roles
                ->copyPermissionTemplatesToCompany(
                    $companyId,
                    $provisionedBy
                );
            $ownerUserId = $this->users
                ->createAdministrationUser(
                    $owner['username'],
                    $owner['email'],
                    $owner['display_name'],
                    $passwordHash,
                    true
                );
            $this->companies->assignOwner(
                $companyId,
                $ownerUserId
            );
            $this->memberships->add(
                $companyId,
                $ownerUserId,
                $provisionedBy,
                true,
                false
            );
            $roleAssigned =
                $this->memberships->assignRoleCode(
                $companyId,
                $ownerUserId,
                'company_owner',
                $provisionedBy
            );

            if (!$roleAssigned) {
                throw new \RuntimeException(
                    'The Company Owner role is not configured.'
                );
            }
            $this->auditLogs->record(
                $provisionedBy,
                'PROVISION_COMPANY',
                'administration',
                'companies',
                (string) $companyId,
                null,
                [
                    'code' => $company['code'],
                    'name' => $company['name'],
                    'subscription_status' =>
                        $company[
                            'subscription_status'
                        ],
                    'subscription_expires_at' =>
                        $company[
                            'subscription_expires_at'
                        ],
                    'module_codes' =>
                        $selectedCodes,
                    'approval_status' => 'pending',
                    'owner_user_id' => $ownerUserId,
                    'owner_username' =>
                        $owner['username'],
                ],
                $companyId
            );

            \db()->commit();
        } catch (Throwable $exception) {
            if (\db()->inTransaction()) {
                \db()->rollBack();
            }

            if (
                $exception instanceof PDOException
                && $exception->getCode() === '23000'
            ) {
                if ($this->users->usernameExists(
                    $owner['username']
                )) {
                    return [
                        'successful' => false,
                        'errors' => [
                            'owner_username' =>
                                'That owner username is already in use.',
                        ],
                    ];
                }

                if ($this->users->emailExists(
                    $owner['email']
                )) {
                    return [
                        'successful' => false,
                        'errors' => [
                            'owner_email' =>
                                'That owner email is already in use.',
                        ],
                    ];
                }

                return [
                    'successful' => false,
                    'errors' => [
                        'code' =>
                            'That company code is already in use.',
                    ],
                ];
            }

            throw $exception;
        }

        return [
            'successful' => true,
            'errors' => [],
            'companyId' => $companyId,
            'ownerUsername' => $owner['username'],
            'temporaryPassword' =>
                $temporaryPassword,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(
        int $companyId,
        int $approvedBy
    ): array {
        $company = $this->companies
            ->findForAdministration($companyId);

        if ($company === null) {
            return [
                'successful' => false,
                'notFound' => true,
                'errors' => [],
            ];
        }

        if (
            (string) (
                $company['approval_status'] ?? ''
            ) === 'approved'
        ) {
            return [
                'successful' => true,
                'notFound' => false,
                'changed' => false,
                'errors' => [],
            ];
        }

        $ownerUserId = (int) (
            $company['owner_user_id'] ?? 0
        );

        if ($ownerUserId < 1) {
            return [
                'successful' => false,
                'notFound' => false,
                'changed' => false,
                'errors' => [
                    'form' =>
                        'Assign a company owner before approval.',
                ],
            ];
        }

        try {
            \db()->beginTransaction();
            $changed = $this->companies->approve(
                $companyId,
                $approvedBy
            );

            if ($changed) {
                $this->memberships->setActive(
                    $companyId,
                    $ownerUserId,
                    true
                );
                $this->auditLogs->record(
                    $approvedBy,
                    'APPROVE_COMPANY',
                    'administration',
                    'companies',
                    (string) $companyId,
                    [
                        'approval_status' =>
                            $company[
                                'approval_status'
                            ],
                        'active' =>
                            (bool) $company['active'],
                    ],
                    [
                        'approval_status' =>
                            'approved',
                        'active' => true,
                        'owner_user_id' =>
                            $ownerUserId,
                    ],
                    $companyId
                );
            }

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
            'changed' => $changed,
            'errors' => [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function details(int $companyId): ?array
    {
        if ($companyId < 1) {
            return null;
        }

        $company = $this->companies
            ->findForAdministration($companyId);

        if ($company === null) {
            return null;
        }

        $company += $this->statusPresentation(
            $company
        );
        $modules = $this->companies
            ->modulesForCompany($companyId);
        $enabledCount = 0;

        foreach ($modules as &$module) {
            $module += $this
                ->modulePresentation($module);

            if (!empty($module['isCurrent'])) {
                $enabledCount++;
            }
        }

        unset($module);

        return [
            'company' => $company,
            'modules' => $modules,
            'enabledModuleCount' =>
                $enabledCount,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function normalizeCompany(
        array $input
    ): array {
        $expiresAt = trim(
            (string) (
                $input['subscription_expires_at']
                ?? ''
            )
        );
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
                        $dateErrors['warning_count']
                        === 0
                        && $dateErrors['error_count']
                        === 0
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
            'code' => strtolower(trim(
                (string) ($input['code'] ?? '')
            )),
            'name' => trim(
                (string) ($input['name'] ?? '')
            ),
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
                    $input['country_code']
                    ?? 'KE'
                )
            )),
            'default_currency' => strtoupper(trim(
                (string) (
                    $input['default_currency']
                    ?? 'KES'
                )
            )),
            'timezone' => trim(
                (string) (
                    $input['timezone']
                    ?? 'Africa/Nairobi'
                )
            ),
            'subscription_status' =>
                strtolower(trim(
                    (string) (
                        $input[
                            'subscription_status'
                        ] ?? 'active'
                    )
                )),
            'subscription_expires_at' =>
                $expiresAt,
            'subscription_expiry_valid' =>
                $expiryValid,
            'brand_primary_color' => strtoupper(
                trim(
                    (string) (
                        $input[
                            'brand_primary_color'
                        ] ?? '#2563EB'
                    )
                )
            ),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, string>
     */
    private function normalizeOwner(array $input): array
    {
        return [
            'display_name' => trim(
                (string) (
                    $input['owner_display_name']
                    ?? ''
                )
            ),
            'username' => strtolower(trim(
                (string) (
                    $input['owner_username']
                    ?? ''
                )
            )),
            'email' => strtolower(trim(
                (string) (
                    $input['owner_email']
                    ?? ''
                )
            )),
        ];
    }

    /**
     * @param array<string, string> $owner
     *
     * @return array<string, string>
     */
    private function validateOwner(array $owner): array
    {
        $errors = [];
        $displayNameLength = mb_strlen(
            $owner['display_name']
        );

        if (
            $displayNameLength < 2
            || $displayNameLength > 120
        ) {
            $errors['owner_display_name'] =
                'Owner name must contain 2-120 characters.';
        }

        if (!preg_match(
            '/^[a-z][a-z0-9._-]{2,49}$/',
            $owner['username']
        )) {
            $errors['owner_username'] =
                'Use 3-50 lowercase letters, numbers, dots, hyphens or underscores.';
        } elseif ($this->users->usernameExists(
            $owner['username']
        )) {
            $errors['owner_username'] =
                'That owner username is already in use.';
        }

        if (
            strlen($owner['email']) > 190
            || !filter_var(
                $owner['email'],
                FILTER_VALIDATE_EMAIL
            )
        ) {
            $errors['owner_email'] =
                'Enter a valid owner email address.';
        } elseif ($this->users->emailExists(
            $owner['email']
        )) {
            $errors['owner_email'] =
                'That owner email is already in use.';
        }

        return $errors;
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
        $code = (string) $company['code'];

        if (
            !preg_match(
                '/^[a-z][a-z0-9-]{2,49}$/',
                $code
            )
        ) {
            $errors['code'] =
                'Use 3–50 lowercase letters, numbers or hyphens, beginning with a letter.';
        } elseif (
            $this->companies->codeExists($code)
        ) {
            $errors['code'] =
                'That company code is already in use.';
        }

        $nameLength = mb_strlen(
            (string) $company['name']
        );

        if (
            $nameLength < 2
            || $nameLength > 150
        ) {
            $errors['name'] =
                'Company name must contain 2–150 characters.';
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
                'Contact phone must contain 5–40 characters.';
        }

        if (
            !preg_match(
                '/^[A-Z]{2}$/',
                (string) $company['country_code']
            )
        ) {
            $errors['country_code'] =
                'Country code must use two letters.';
        }

        if (
            !preg_match(
                '/^[A-Z]{3}$/',
                (string) $company[
                    'default_currency'
                ]
            )
        ) {
            $errors['default_currency'] =
                'Currency must use a three-letter ISO code.';
        }

        if (
            !in_array(
                $company['timezone'],
                DateTimeZone::listIdentifiers(),
                true
            )
        ) {
            $errors['timezone'] =
                'Select a valid timezone.';
        }

        if (
            !in_array(
                $company['subscription_status'],
                ['active', 'trial'],
                true
            )
        ) {
            $errors['subscription_status'] =
                'Select Active or Trial.';
        }

        $expiresAt = $company[
            'subscription_expires_at'
        ];

        if (empty(
            $company[
                'subscription_expiry_valid'
            ]
        )) {
            $errors['subscription_expires_at'] =
                'Enter a valid expiry date.';
        } elseif (
            $company['subscription_status']
            === 'trial'
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

        if (
            !preg_match(
                '/^#[0-9A-F]{6}$/',
                (string) $company[
                    'brand_primary_color'
                ]
            )
        ) {
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
    private function normalizeCodes(
        mixed $value
    ): array {
        if (!is_array($value)) {
            return [];
        }

        $codes = [];

        foreach ($value as $code) {
            if (
                is_string($code)
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

    private function nullable(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $company
     *
     * @return array{
     *     statusLabel: string,
     *     statusTone: string,
     *     isExpired: bool
     * }
     */
    private function statusPresentation(
        array $company
    ): array {
        $expiresAt = $company[
            'subscription_expires_at'
        ] ?? null;
        $expiryTimestamp = is_string($expiresAt)
            ? strtotime($expiresAt)
            : false;
        $expired = $expiryTimestamp !== false
            && $expiryTimestamp <= time();
        $status = (string) (
            $company['subscription_status']
            ?? 'active'
        );

        if (empty($company['active'])) {
            if (
                (string) (
                    $company['approval_status']
                    ?? ''
                ) === 'pending'
            ) {
                return [
                    'statusLabel' =>
                        'Pending approval',
                    'statusTone' => 'warning',
                    'isExpired' => $expired,
                ];
            }

            return [
                'statusLabel' => 'Inactive',
                'statusTone' => 'muted',
                'isExpired' => $expired,
            ];
        }

        if ($expired) {
            return [
                'statusLabel' => 'Expired',
                'statusTone' => 'danger',
                'isExpired' => true,
            ];
        }

        return match ($status) {
            'trial' => [
                'statusLabel' => 'Trial',
                'statusTone' => 'warning',
                'isExpired' => false,
            ],
            'suspended' => [
                'statusLabel' => 'Suspended',
                'statusTone' => 'danger',
                'isExpired' => false,
            ],
            default => [
                'statusLabel' => 'Active',
                'statusTone' => 'success',
                'isExpired' => false,
            ],
        };
    }

    /**
     * @param array<string, mixed> $module
     *
     * @return array{
     *     isCurrent: bool,
     *     licenseLabel: string,
     *     licenseTone: string
     * }
     */
    private function modulePresentation(
        array $module
    ): array {
        $expiresAt =
            $module['expires_at'] ?? null;
        $expiryTimestamp = is_string($expiresAt)
            ? strtotime($expiresAt)
            : false;
        $expired = $expiryTimestamp !== false
            && $expiryTimestamp <= time();
        $status = (string) (
            $module['license_status']
            ?? 'not_licensed'
        );
        $current = !empty($module['enabled'])
            && !empty($module['available'])
            && in_array(
                $status,
                ['active', 'trial'],
                true
            )
            && !$expired;

        if ($current) {
            return [
                'isCurrent' => true,
                'licenseLabel' => $status === 'trial'
                    ? 'Trial enabled'
                    : 'Licensed',
                'licenseTone' => $status === 'trial'
                    ? 'warning'
                    : 'success',
            ];
        }

        if ($expired) {
            return [
                'isCurrent' => false,
                'licenseLabel' => 'Expired',
                'licenseTone' => 'danger',
            ];
        }

        return [
            'isCurrent' => false,
            'licenseLabel' => !empty(
                $module['available']
            )
                ? 'Not licensed'
                : 'Roadmap',
            'licenseTone' => 'muted',
        ];
    }
}
