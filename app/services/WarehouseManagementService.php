<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogWriter;
use App\Repositories\RepositoryFactory;
use App\Repositories\WarehouseRepository;
use PDOException;
use Throwable;

final class WarehouseManagementService
{
    private const WAREHOUSE_TYPES = [
        'standard',
        'retail',
        'distribution',
        'transit',
        'returns',
        'virtual',
    ];

    private const OPERATION_TYPE_CODES = [
        'RCPT',
        'INT',
        'DLV',
        'ADJ',
    ];

    private WarehouseRepository $warehouses;
    private AuditLogWriter $auditLogs;
    private TenantContext $tenant;

    public function __construct(
        ?WarehouseRepository $warehouses = null,
        ?AuditLogWriter $auditLogs = null,
        ?TenantContext $tenant = null
    ) {
        $this->warehouses = $warehouses
            ?? RepositoryFactory::warehouses();
        $this->auditLogs = $auditLogs
            ?? RepositoryFactory::auditLogs();
        $this->tenant = $tenant
            ?? new TenantContext();
    }

    /**
     * @return array{
     *     warehouses: list<array<string, mixed>>,
     *     summary: array{
     *         total: int,
     *         active: int,
     *         defaults: int,
     *         ready: int
     *     }
     * }
     */
    public function listing(): array
    {
        $warehouses = $this->warehouses->listForCompany(
            $this->tenant->companyId()
        );
        $active = 0;
        $defaults = 0;
        $ready = 0;

        foreach ($warehouses as &$warehouse) {
            $warehouse['warehouse_id'] = (int) (
                $warehouse['warehouse_id'] ?? 0
            );
            $warehouse['branch_id'] = $this->nullableInteger(
                $warehouse['branch_id'] ?? null
            );
            $warehouse['manager_user_id'] =
                $this->nullableInteger(
                    $warehouse['manager_user_id'] ?? null
                );
            $warehouse['allow_negative_stock'] =
                !empty($warehouse['allow_negative_stock']);
            $warehouse['is_default'] =
                !empty($warehouse['is_default']);
            $warehouse['active'] =
                !empty($warehouse['active']);
            $warehouse['active_operation_type_count'] =
                (int) (
                    $warehouse['active_operation_type_count']
                    ?? 0
                );
            $warehouse[
                'active_default_operation_type_count'
            ] = (int) (
                $warehouse[
                    'active_default_operation_type_count'
                ] ?? 0
            );
            $warehouse['operation_types_ready'] =
                $warehouse['active_operation_type_count'] === 4
                && $warehouse[
                    'active_default_operation_type_count'
                ] === 4;

            $active += $warehouse['active'] ? 1 : 0;
            $defaults += $warehouse['is_default'] ? 1 : 0;
            $ready += $warehouse['operation_types_ready'] ? 1 : 0;
        }

        unset($warehouse);

        return [
            'warehouses' => $warehouses,
            'summary' => [
                'total' => count($warehouses),
                'active' => $active,
                'defaults' => $defaults,
                'ready' => $ready,
            ],
        ];
    }

    /**
     * @return array{
     *     branches: list<array<string, mixed>>,
     *     managers: list<array<string, mixed>>,
     *     warehouseTypes: array<string, string>
     * }
     */
    public function formOptions(): array
    {
        $companyId = $this->tenant->companyId();

        return [
            'branches' => $this->warehouses
                ->activeBranchesForCompany($companyId),
            'managers' => $this->warehouses
                ->activeManagersForCompany($companyId),
            'warehouseTypes' => [
                'standard' => 'Standard',
                'retail' => 'Retail',
                'distribution' => 'Distribution',
                'transit' => 'Transit',
                'returns' => 'Returns',
                'virtual' => 'Virtual',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [
            'code' => '',
            'name' => '',
            'warehouse_type' => 'standard',
            'branch_id' => null,
            'manager_user_id' => null,
            'address' => null,
            'phone' => null,
            'email' => null,
            'allow_negative_stock' => false,
            'is_default' => false,
            'active' => true,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     successful: bool,
     *     errors: array<string, string>,
     *     warehouseId?: int,
     *     warehouseName?: string
     * }
     */
    public function create(
        array $input,
        int $createdBy
    ): array {
        if ($createdBy < 1) {
            return [
                'successful' => false,
                'errors' => [
                    'form' => 'A valid actor ID is required.',
                ],
            ];
        }

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
        $ownsTransaction = !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $this->warehouses->lockCompany($companyId);
            $concurrencyErrors = $this->validateLocked(
                $companyId,
                $values
            );

            if ($concurrencyErrors !== []) {
                if (
                    $ownsTransaction
                    && $connection->inTransaction()
                ) {
                    $connection->rollBack();
                }

                return [
                    'successful' => false,
                    'errors' => $concurrencyErrors,
                ];
            }

            $warehouseId = $this->warehouses->create(
                $companyId,
                $values,
                $createdBy
            );
            $this->warehouses->createDefaultOperationTypes(
                $companyId,
                $warehouseId
            );
            $this->auditLogs->record(
                $createdBy,
                'CREATE',
                'inventory',
                'inventory_warehouses',
                (string) $warehouseId,
                null,
                $this->auditValues($values),
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

            if ((string) $exception->getCode() === '23000') {
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
            'warehouseId' => $warehouseId,
            'warehouseName' => (string) $values['name'],
        ];
    }

    /**
     * @param array<string, mixed> $input
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
            'warehouse_type' => strtolower(trim((string) (
                $input['warehouse_type'] ?? 'standard'
            ))),
            'branch_id' => $this->optionalIntegerInput(
                $input['branch_id'] ?? null
            ),
            'manager_user_id' => $this->optionalIntegerInput(
                $input['manager_user_id'] ?? null
            ),
            'address' => $this->nullableString(
                $input['address'] ?? null
            ),
            'phone' => $this->nullableString(
                $input['phone'] ?? null
            ),
            'email' => $this->nullableEmail(
                $input['email'] ?? null
            ),
            'allow_negative_stock' =>
                !empty($input['allow_negative_stock']),
            'is_default' => !empty($input['is_default']),
            'active' => !empty($input['active']),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private function validate(
        int $companyId,
        array $values
    ): array {
        $errors = [];
        $code = (string) $values['code'];
        $name = (string) $values['name'];
        $warehouseType = (string) $values['warehouse_type'];

        if (
            preg_match(
                '/^[A-Z][A-Z0-9_-]{1,39}$/',
                $code
            ) !== 1
        ) {
            $errors['code'] =
                'Code must contain 2-40 uppercase letters, numbers, hyphens or underscores and begin with a letter.';
        } elseif (
            $this->warehouses->codeExists(
                $companyId,
                $code
            )
        ) {
            $errors['code'] =
                'That warehouse code is already in use.';
        }

        if (
            mb_strlen($name) < 2
            || mb_strlen($name) > 160
        ) {
            $errors['name'] =
                'Warehouse name must contain 2-160 characters.';
        }

        if (!in_array(
            $warehouseType,
            self::WAREHOUSE_TYPES,
            true
        )) {
            $errors['warehouse_type'] =
                'Select a valid warehouse type.';
        }

        $this->validateOptionalLength(
            $errors,
            'address',
            $values['address'],
            255,
            'Address'
        );
        $this->validateOptionalLength(
            $errors,
            'phone',
            $values['phone'],
            40,
            'Phone'
        );

        if (
            is_string($values['email'])
            && (
                mb_strlen($values['email']) > 190
                || filter_var(
                    $values['email'],
                    FILTER_VALIDATE_EMAIL
                ) === false
            )
        ) {
            $errors['email'] =
                'Enter a valid email address of no more than 190 characters.';
        }

        if (
            $values['branch_id'] !== null
            && (
                !is_int($values['branch_id'])
                || $values['branch_id'] < 1
            )
        ) {
            $errors['branch_id'] =
                'Select a valid branch.';
        } elseif (
            is_int($values['branch_id'])
            && !$this->warehouses->branchBelongsToCompany(
                $companyId,
                $values['branch_id']
            )
        ) {
            $errors['branch_id'] =
                'Select an active branch from this company.';
        }

        if (
            $values['manager_user_id'] !== null
            && (
                !is_int($values['manager_user_id'])
                || $values['manager_user_id'] < 1
            )
        ) {
            $errors['manager_user_id'] =
                'Select a valid manager.';
        } elseif (
            is_int($values['manager_user_id'])
            && !$this->warehouses->managerBelongsToCompany(
                $companyId,
                $values['manager_user_id']
            )
        ) {
            $errors['manager_user_id'] =
                'Select an active manager from this company.';
        }

        if (
            !empty($values['is_default'])
            && $this->warehouses->defaultWarehouseId(
                $companyId
            ) !== null
        ) {
            $errors['is_default'] =
                'This company already has a default warehouse.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private function validateLocked(
        int $companyId,
        array $values
    ): array {
        $errors = [];

        if ($this->warehouses->codeExists(
            $companyId,
            (string) $values['code']
        )) {
            $errors['code'] =
                'That warehouse code is already in use.';
        }

        if (
            !empty($values['is_default'])
            && $this->warehouses->defaultWarehouseId(
                $companyId,
                true
            ) !== null
        ) {
            $errors['is_default'] =
                'This company already has a default warehouse.';
        }

        if (
            $values['branch_id'] !== null
            && (
                !is_int($values['branch_id'])
                || $values['branch_id'] < 1
            )
        ) {
            $errors['branch_id'] =
                'Select a valid branch.';
        } elseif (
            is_int($values['branch_id'])
            && !$this->warehouses->branchBelongsToCompany(
                $companyId,
                $values['branch_id']
            )
        ) {
            $errors['branch_id'] =
                'Select an active branch from this company.';
        }

        if (
            $values['manager_user_id'] !== null
            && (
                !is_int($values['manager_user_id'])
                || $values['manager_user_id'] < 1
            )
        ) {
            $errors['manager_user_id'] =
                'Select a valid manager.';
        } elseif (
            is_int($values['manager_user_id'])
            && !$this->warehouses->managerBelongsToCompany(
                $companyId,
                $values['manager_user_id']
            )
        ) {
            $errors['manager_user_id'] =
                'Select an active manager from this company.';
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
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function auditValues(array $values): array
    {
        return $values + [
            'operation_type_codes' =>
                self::OPERATION_TYPE_CODES,
        ];
    }


    private function optionalIntegerInput(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : -1;
        }

        $value = trim((string) $value);

        return ctype_digit($value) && (int) $value > 0
            ? (int) $value
            : -1;
    }

    private function nullableInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        $value = trim((string) $value);

        return $value !== '' && ctype_digit($value)
            && (int) $value > 0
                ? (int) $value
                : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableEmail(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

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
                    'The warehouse could not be created because its code or default status conflicts with existing data.',
            ],
        ];
    }
}
