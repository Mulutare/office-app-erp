<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogWriter;
use App\Repositories\BranchRepository;
use App\Repositories\RepositoryFactory;
use DateTimeZone;
use PDOException;
use Throwable;

final class BranchManagementService
{
    private BranchRepository $branches;
    private AuditLogWriter $auditLogs;
    private TenantContext $tenant;

    public function __construct(
        ?BranchRepository $branches = null,
        ?AuditLogWriter $auditLogs = null,
        ?TenantContext $tenant = null
    ) {
        $this->branches = $branches
            ?? RepositoryFactory::branches();
        $this->auditLogs = $auditLogs
            ?? RepositoryFactory::auditLogs();
        $this->tenant = $tenant
            ?? new TenantContext();
    }

    /**
     * @return array{
     *     branches: list<array<string, mixed>>,
     *     summary: array{
     *         total: int,
     *         active: int,
     *         headOffices: int
     *     }
     * }
     */
    public function listing(): array
    {
        $branches = $this->branches
            ->listForCompany(
                $this->tenant->companyId()
            );
        $active = 0;
        $headOffices = 0;

        foreach ($branches as &$branch) {
            $branch['branch_id'] = (int) (
                $branch['branch_id'] ?? 0
            );
            $branch['active'] =
                !empty($branch['active']);
            $branch['is_head_office'] =
                !empty($branch['is_head_office']);
            $active += $branch['active'] ? 1 : 0;
            $headOffices +=
                $branch['is_head_office'] ? 1 : 0;
        }

        unset($branch);

        return [
            'branches' => $branches,
            'summary' => [
                'total' => count($branches),
                'active' => $active,
                'headOffices' => $headOffices,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function form(int $branchId): ?array
    {
        $branch = $this->branches->find(
            $this->tenant->companyId(),
            $branchId
        );

        if ($branch === null) {
            return null;
        }

        return $this->recordValues($branch)
            + [
                'branch_id' => (int) (
                    $branch['branch_id'] ?? 0
                ),
            ];
    }

    /**
     * @return array{
     *     country_code: string,
     *     timezone: string
     * }
     */
    public function defaults(): array
    {
        $company = $this->tenant->company();
        $countryCode = strtoupper(trim((string) (
            $company['country_code'] ?? 'KE'
        )));
        $timezone = trim((string) (
            $company['timezone'] ?? 'Africa/Nairobi'
        ));

        return [
            'country_code' =>
                $countryCode !== ''
                    ? $countryCode
                    : 'KE',
            'timezone' =>
                $timezone !== ''
                    ? $timezone
                    : 'Africa/Nairobi',
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     successful: bool,
     *     errors: array<string, string>,
     *     branchId?: int,
     *     branchName?: string
     * }
     */
    public function create(
        array $input,
        int $createdBy
    ): array {
        $companyId = $this->tenant->companyId();
        $values = $this->normalize($input);
        $errors = $this->validate(
            $companyId,
            $values
        );

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
            ];
        }

        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $this->branches->lockCompany($companyId);

            if (
                !empty($values['is_head_office'])
                && $this->branches->headOfficeId(
                    $companyId,
                    null,
                    true
                ) !== null
            ) {
                if ($ownsTransaction) {
                    $connection->rollBack();
                }

                return [
                    'successful' => false,
                    'errors' => [
                        'is_head_office' =>
                            'This company already has a head office.',
                    ],
                ];
            }

            $branchId = $this->branches->create(
                $companyId,
                $values,
                $createdBy
            );
            $this->auditLogs->record(
                $createdBy,
                'CREATE',
                'organization',
                'organization_branches',
                (string) $branchId,
                null,
                $this->recordValues($values),
                $companyId
            );

            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (PDOException $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            if (
                (string) $exception->getCode()
                === '23000'
            ) {
                return $this->conflictResult();
            }

            throw $exception;
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            throw $exception;
        }

        return [
            'successful' => true,
            'errors' => [],
            'branchId' => $branchId,
            'branchName' => (string) $values['name'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     successful: bool,
     *     notFound?: bool,
     *     errors: array<string, string>,
     *     branchId?: int,
     *     branchName?: string,
     *     changed?: bool
     * }
     */
    public function update(
        int $branchId,
        array $input,
        int $updatedBy
    ): array {
        $companyId = $this->tenant->companyId();
        $branch = $this->branches->find(
            $companyId,
            $branchId
        );

        if ($branch === null) {
            return [
                'successful' => false,
                'notFound' => true,
                'errors' => [],
            ];
        }

        $values = $this->normalize($input);
        $errors = $this->validate(
            $companyId,
            $values,
            $branchId
        );

        if ($errors !== []) {
            return [
                'successful' => false,
                'errors' => $errors,
            ];
        }

        $oldValues = $this->recordValues($branch);
        $newValues = $this->recordValues($values);

        if ($oldValues === $newValues) {
            return [
                'successful' => true,
                'errors' => [],
                'branchId' => $branchId,
                'branchName' => (string) $values['name'],
                'changed' => false,
            ];
        }

        $connection = \db();
        $ownsTransaction =
            !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $this->branches->lockCompany($companyId);
            $branch = $this->branches->find(
                $companyId,
                $branchId
            );

            if ($branch === null) {
                if ($ownsTransaction) {
                    $connection->rollBack();
                }

                return [
                    'successful' => false,
                    'notFound' => true,
                    'errors' => [],
                ];
            }

            if (
                !empty($values['is_head_office'])
                && $this->branches->headOfficeId(
                    $companyId,
                    $branchId,
                    true
                ) !== null
            ) {
                if ($ownsTransaction) {
                    $connection->rollBack();
                }

                return [
                    'successful' => false,
                    'errors' => [
                        'is_head_office' =>
                            'This company already has a head office.',
                    ],
                ];
            }

            $this->branches->update(
                $companyId,
                $branchId,
                $values,
                $updatedBy
            );
            $this->auditLogs->record(
                $updatedBy,
                'UPDATE',
                'organization',
                'organization_branches',
                (string) $branchId,
                $oldValues,
                $newValues,
                $companyId
            );

            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (PDOException $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            if (
                (string) $exception->getCode()
                === '23000'
            ) {
                return $this->conflictResult();
            }

            throw $exception;
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            throw $exception;
        }

        return [
            'successful' => true,
            'errors' => [],
            'branchId' => $branchId,
            'branchName' => (string) $values['name'],
            'changed' => true,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        return [
            'code' => strtoupper(trim((string) (
                $input['code'] ?? ''
            ))),
            'name' => trim((string) (
                $input['name'] ?? ''
            )),
            'contact_email' => $this->nullable(
                $input['contact_email'] ?? null
            ),
            'contact_phone' => $this->nullable(
                $input['contact_phone'] ?? null
            ),
            'address_line' => $this->nullable(
                $input['address_line'] ?? null
            ),
            'city' => $this->nullable(
                $input['city'] ?? null
            ),
            'country_code' => strtoupper(trim(
                (string) (
                    $input['country_code'] ?? ''
                )
            )),
            'timezone' => trim((string) (
                $input['timezone'] ?? ''
            )),
            'attendance_geofence_enabled' =>
                !empty($input['attendance_geofence_enabled']),
            'attendance_latitude' => $this->nullable(
                $input['attendance_latitude'] ?? null
            ),
            'attendance_longitude' => $this->nullable(
                $input['attendance_longitude'] ?? null
            ),
            'attendance_radius_meters' => $this->nullable(
                $input['attendance_radius_meters'] ?? null
            ),
            'is_head_office' =>
                !empty($input['is_head_office']),
            'active' => !empty($input['active']),
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, string>
     */
    private function validate(
        int $companyId,
        array $values,
        ?int $ignoreBranchId = null
    ): array {
        $errors = [];
        $code = (string) $values['code'];
        $name = (string) $values['name'];
        $email = $values['contact_email'];
        $phone = $values['contact_phone'];
        $address = $values['address_line'];
        $city = $values['city'];
        $countryCode =
            (string) $values['country_code'];
        $timezone = (string) $values['timezone'];
        $geofenceEnabled =
            !empty($values['attendance_geofence_enabled']);
        $latitude = $values['attendance_latitude'];
        $longitude = $values['attendance_longitude'];
        $radius = $values['attendance_radius_meters'];

        if (
            preg_match(
                '/^[A-Z][A-Z0-9_-]{1,29}$/',
                $code
            ) !== 1
        ) {
            $errors['code'] =
                'Code must contain 2-30 uppercase letters, numbers, hyphens or underscores and begin with a letter.';
        } elseif (
            $this->branches->codeExists(
                $companyId,
                $code,
                $ignoreBranchId
            )
        ) {
            $errors['code'] =
                'That branch code is already in use.';
        }

        if (
            mb_strlen($name) < 2
            || mb_strlen($name) > 120
        ) {
            $errors['name'] =
                'Branch name must contain 2-120 characters.';
        } elseif (
            $this->branches->nameExists(
                $companyId,
                $name,
                $ignoreBranchId
            )
        ) {
            $errors['name'] =
                'That branch name is already in use.';
        }

        if (
            is_string($email)
            && (
                mb_strlen($email) > 190
                || filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                ) === false
            )
        ) {
            $errors['contact_email'] =
                'Enter a valid email address of no more than 190 characters.';
        }

        $this->validateOptionalLength(
            $errors,
            'contact_phone',
            $phone,
            40,
            'Contact phone'
        );
        $this->validateOptionalLength(
            $errors,
            'address_line',
            $address,
            190,
            'Address'
        );
        $this->validateOptionalLength(
            $errors,
            'city',
            $city,
            100,
            'City'
        );

        if (
            preg_match(
                '/^[A-Z]{2}$/',
                $countryCode
            ) !== 1
        ) {
            $errors['country_code'] =
                'Country code must contain two letters.';
        }

        if (
            mb_strlen($timezone) > 80
            || !in_array(
                $timezone,
                DateTimeZone::listIdentifiers(),
                true
            )
        ) {
            $errors['timezone'] =
                'Select a valid IANA timezone.';
        }

        if (
            $latitude !== null
            && (
                !is_numeric($latitude)
                || (float) $latitude < -90
                || (float) $latitude > 90
            )
        ) {
            $errors['attendance_latitude'] =
                'Latitude must be between -90 and 90.';
        }

        if (
            $longitude !== null
            && (
                !is_numeric($longitude)
                || (float) $longitude < -180
                || (float) $longitude > 180
            )
        ) {
            $errors['attendance_longitude'] =
                'Longitude must be between -180 and 180.';
        }

        if (
            $radius !== null
            && (
                filter_var(
                    $radius,
                    FILTER_VALIDATE_INT
                ) === false
                || (int) $radius < 10
                || (int) $radius > 50000
            )
        ) {
            $errors['attendance_radius_meters'] =
                'Attendance radius must be between 10 and 50,000 meters.';
        }

        if ($geofenceEnabled) {
            if ($latitude === null) {
                $errors['attendance_latitude'] =
                    'Select the attendance location.';
            }

            if ($longitude === null) {
                $errors['attendance_longitude'] =
                    'Select the attendance location.';
            }

            if ($radius === null) {
                $errors['attendance_radius_meters'] =
                    'Enter the allowed attendance radius.';
            }

            if (empty($values['active'])) {
                $errors['active'] =
                    'A branch with attendance location enforcement must remain active.';
            }
        }

        if (
            !empty($values['is_head_office'])
            && empty($values['active'])
        ) {
            $errors['active'] =
                'A head office must remain active.';
        }

        return $errors;
    }

    /**
     * @param array<string, string> $errors
     */
    private function validateOptionalLength(
        array &$errors,
        string $field,
        mixed $value,
        int $maximum,
        string $label
    ): void {
        if (
            is_string($value)
            && mb_strlen($value) > $maximum
        ) {
            $errors[$field] = sprintf(
                '%s cannot exceed %d characters.',
                $label,
                $maximum
            );
        }
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function recordValues(array $record): array
    {
        return [
            'code' => (string) (
                $record['code'] ?? ''
            ),
            'name' => (string) (
                $record['name'] ?? ''
            ),
            'contact_email' => $this->nullable(
                $record['contact_email'] ?? null
            ),
            'contact_phone' => $this->nullable(
                $record['contact_phone'] ?? null
            ),
            'address_line' => $this->nullable(
                $record['address_line'] ?? null
            ),
            'city' => $this->nullable(
                $record['city'] ?? null
            ),
            'country_code' => (string) (
                $record['country_code'] ?? ''
            ),
            'timezone' => (string) (
                $record['timezone'] ?? ''
            ),
            'attendance_geofence_enabled' =>
                !empty($record['attendance_geofence_enabled']),
            'attendance_latitude' => $this->nullable(
                $record['attendance_latitude'] ?? null
            ),
            'attendance_longitude' => $this->nullable(
                $record['attendance_longitude'] ?? null
            ),
            'attendance_radius_meters' => $this->nullable(
                $record['attendance_radius_meters'] ?? null
            ),
            'is_head_office' =>
                !empty($record['is_head_office']),
            'active' => !empty($record['active']),
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{
     *     successful: false,
     *     errors: array<string, string>
     * }
     */
    private function conflictResult(): array
    {
        return [
            'successful' => false,
            'errors' => [
                'form' =>
                    'A branch with that code or name already exists.',
            ],
        ];
    }
}
