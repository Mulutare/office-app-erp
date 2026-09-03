<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogWriter;
use App\Repositories\RepositoryFactory;
use App\Repositories\WarehouseLocationRepository;
use App\Repositories\WarehouseRepository;
use PDOException;
use RuntimeException;
use Throwable;

final class WarehouseLocationManagementService
{
    private const LOCATION_TYPES = [
        'zone',
        'aisle',
        'rack',
        'shelf',
        'bin',
        'receiving',
        'dispatch',
        'returns',
        'quarantine',
    ];

    private WarehouseLocationRepository $locations;
    private AuditLogWriter $auditLogs;
    private TenantContext $tenant;
    private WarehouseRepository $warehouses;

    public function __construct(
        ?WarehouseLocationRepository $locations = null,
        ?AuditLogWriter $auditLogs = null,
        ?TenantContext $tenant = null,
        ?WarehouseRepository $warehouses = null
    ) {
        $this->locations = $locations
            ?? RepositoryFactory::warehouseLocations();
        $this->auditLogs = $auditLogs
            ?? RepositoryFactory::auditLogs();
        $this->tenant = $tenant
            ?? new TenantContext();
        $this->warehouses=$warehouses??RepositoryFactory::warehouses();
    }

    /**
     * @return array{
     *     locations: list<array<string, mixed>>,
     *     warehouses: list<array<string, mixed>>,
     *     summary: array{
     *         total: int,
     *         active: int,
     *         receiving: int,
     *         picking: int
     *     }
     * }
     */
    public function listing(): array
    {
        $companyId = $this->tenant->companyId();
        $locations = $this->locations->listForCompany(
            $companyId
        );
        $actorId=(int)($_SESSION['auth']['user_id']??0);
        $access=new InventoryOperationalAccessService();
        $locations=array_values(array_filter($locations,static fn(array $location):bool=>$access->canAccessLocation($companyId,$actorId,(int)($location['warehouse_id']??0),(int)($location['location_id']??0))));
        $active = 0;
        $receiving = 0;
        $picking = 0;

        foreach ($locations as &$location) {
            $location['location_id'] = (int) (
                $location['location_id'] ?? 0
            );
            $location['warehouse_id'] = (int) (
                $location['warehouse_id'] ?? 0
            );
            $location['parent_location_id'] =
                $this->nullableInteger(
                    $location['parent_location_id'] ?? null
                );
            $location['pick_priority'] = (int) (
                $location['pick_priority'] ?? 100
            );
            $location['receiving_allowed'] =
                !empty($location['receiving_allowed']);
            $location['picking_allowed'] =
                !empty($location['picking_allowed']);
            $location['active'] =
                !empty($location['active']);

            $active += $location['active'] ? 1 : 0;
            $receiving += $location['receiving_allowed']
                ? 1
                : 0;
            $picking += $location['picking_allowed']
                ? 1
                : 0;
        }

        unset($location);

        return [
            'locations' => $locations,
            'warehouses' => $access->warehousesForUser($companyId,$actorId),
            'summary' => [
                'total' => count($locations),
                'active' => $active,
                'receiving' => $receiving,
                'picking' => $picking,
            ],
        ];
    }

    /**
     * @return array{
     *     warehouses: list<array<string, mixed>>,
     *     parents: list<array<string, mixed>>,
     *     locationTypes: array<string, string>
     * }
     */
    public function formOptions(): array
    {
        $companyId = $this->tenant->companyId();

        $parents = array_values(array_filter(
            $this->locations->listForCompany($companyId),
            static fn (array $location): bool =>
                !empty($location['active'])
        ));

        return [
            'warehouses' => $this->locations
                ->activeWarehousesForCompany($companyId),
            'parents' => $parents,
            'locationTypes' => [
                'zone' => 'Zone',
                'aisle' => 'Aisle',
                'rack' => 'Rack',
                'shelf' => 'Shelf',
                'bin' => 'Bin',
                'receiving' => 'Receiving',
                'dispatch' => 'Dispatch',
                'returns' => 'Returns',
                'quarantine' => 'Quarantine',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [
            'warehouse_id' => null,
            'parent_location_id' => null,
            'code' => '',
            'name' => '',
            'location_type' => 'bin',
            'barcode' => null,
            'aisle' => null,
            'rack' => null,
            'shelf' => null,
            'bin' => null,
            'pick_priority' => 100,
            'receiving_allowed' => true,
            'picking_allowed' => true,
            'active' => true,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{
     *     successful: bool,
     *     errors: array<string, string>,
     *     locationId?: int,
     *     locationName?: string
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

            $warehouse = $this->locations
                ->warehouseForUpdate(
                    $companyId,
                    (int) $values['warehouse_id']
                );

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

            $locationId = $this->locations->create(
                $companyId,
                $values,
                $createdBy
            );

            $this->auditLogs->record(
                $createdBy,
                'CREATE',
                'inventory',
                'inventory_warehouse_locations',
                (string) $locationId,
                null,
                $values + [
                    'warehouse_code' =>
                        (string) ($warehouse['code'] ?? ''),
                ],
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
        } catch (RuntimeException $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            return [
                'successful' => false,
                'errors' => [
                    'form' => $exception->getMessage(),
                ],
            ];
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
            'locationId' => $locationId,
            'locationName' => (string) $values['name'],
        ];
    }

    /**
     * @return array{
     *     successful: bool,
     *     errors: array<string, string>,
     *     locationCount?: int,
     *     warehouseName?: string
     * }
     */
    public function provisionDefaults(
        int $warehouseId,
        int $actorId
    ): array {
        if ($warehouseId < 1 || $actorId < 1) {
            return [
                'successful' => false,
                'errors' => [
                    'form' =>
                        'A valid warehouse and actor are required.',
                ],
            ];
        }

        $companyId = $this->tenant->companyId();
        $connection = \db();
        $ownsTransaction = !$connection->inTransaction();

        try {
            if ($ownsTransaction) {
                $connection->beginTransaction();
            }

            $warehouse = $this->locations
                ->warehouseForUpdate(
                    $companyId,
                    $warehouseId
                );
            $locationIds = $this->locations
                ->provisionOperationalDefaults(
                    $companyId,
                    $warehouseId,
                    (string) $warehouse['code'],
                    (string) $warehouse['name'],
                    $actorId
                );
            $this->warehouses->createDefaultOperationTypes($companyId,$warehouseId);
            $this->locations
                ->configureDefaultOperationLocations(
                    $companyId,
                    $warehouseId,
                    $locationIds
                );
            $readinessRows=$this->locations->readinessForCompany($companyId);$readiness=null;
            foreach($readinessRows as $row){if((int)($row['warehouse_id']??0)===$warehouseId){$readiness=$row;break;}}
            if(!is_array($readiness)||(int)$readiness['operational_location_count']!==6||(int)$readiness['mapped_operation_type_count']!==4)throw new RuntimeException('Warehouse provisioning did not produce six valid operational locations and four mapped routes.');

            $this->auditLogs->record(
                $actorId,
                'UPDATE',
                'inventory',
                'inventory_warehouses',
                (string) $warehouseId,
                null,
                [
                    'action' =>
                        'provision_operational_locations',
                    'location_codes' =>
                        $this->defaultCodes(
                            (string) $warehouse['code']
                        ),
                ],
                $companyId
            );

            if ($ownsTransaction) {
                $connection->commit();
            }
        } catch (Throwable $exception) {
            if (
                $ownsTransaction
                && $connection->inTransaction()
            ) {
                $connection->rollBack();
            }

            return [
                'successful' => false,
                'errors' => [
                    'form' => $exception->getMessage(),
                ],
            ];
        }

        return [
            'successful' => true,
            'errors' => [],
            'locationCount' => count($locationIds),
            'routeCount'=>4,
            'warehouseName' =>
                (string) $warehouse['name'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        return [
            'warehouse_id' => $this->optionalIntegerInput(
                $input['warehouse_id'] ?? null
            ),
            'parent_location_id' =>
                $this->optionalIntegerInput(
                    $input['parent_location_id'] ?? null
                ),
            'code' => strtoupper(trim((string) (
                $input['code'] ?? ''
            ))),
            'name' => trim((string) (
                $input['name'] ?? ''
            )),
            'location_type' => strtolower(trim((string) (
                $input['location_type'] ?? 'bin'
            ))),
            'barcode' => $this->nullableString(
                $input['barcode'] ?? null
            ),
            'aisle' => $this->nullableString(
                $input['aisle'] ?? null
            ),
            'rack' => $this->nullableString(
                $input['rack'] ?? null
            ),
            'shelf' => $this->nullableString(
                $input['shelf'] ?? null
            ),
            'bin' => $this->nullableString(
                $input['bin'] ?? null
            ),
            'pick_priority' =>
                $this->positiveIntegerInput(
                    $input['pick_priority'] ?? 100
                ),
            'receiving_allowed' =>
                !empty($input['receiving_allowed']),
            'picking_allowed' =>
                !empty($input['picking_allowed']),
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
        $warehouseId = $values['warehouse_id'];
        $parentId = $values['parent_location_id'];
        $code = (string) $values['code'];
        $name = (string) $values['name'];
        $type = (string) $values['location_type'];

        if (!is_int($warehouseId) || $warehouseId < 1) {
            $errors['warehouse_id'] =
                'Select an active warehouse.';
        } elseif (
            !$this->locations->warehouseBelongsToCompany(
                $companyId,
                $warehouseId
            )
        ) {
            $errors['warehouse_id'] =
                'Select an active warehouse from this company.';
        }

        if (
            preg_match(
                '/^[A-Z][A-Z0-9_\/-]{1,59}$/',
                $code
            ) !== 1
        ) {
            $errors['code'] =
                'Code must contain 2-60 uppercase letters, numbers, slashes, hyphens or underscores and begin with a letter.';
        } elseif (
            is_int($warehouseId)
            && $warehouseId > 0
            && $this->locations->codeExists(
                $companyId,
                $warehouseId,
                $code
            )
        ) {
            $errors['code'] =
                'That location code already exists in the warehouse.';
        }

        if (
            mb_strlen($name) < 2
            || mb_strlen($name) > 160
        ) {
            $errors['name'] =
                'Location name must contain 2-160 characters.';
        }

        if (!in_array($type, self::LOCATION_TYPES, true)) {
            $errors['location_type'] =
                'Select a valid location type.';
        }

        if (
            $parentId !== null
            && (
                !is_int($parentId)
                || $parentId < 1
            )
        ) {
            $errors['parent_location_id'] =
                'Select a valid parent location.';
        } elseif (
            is_int($warehouseId)
            && $warehouseId > 0
            && is_int($parentId)
            && !$this->locations
                ->parentBelongsToWarehouse(
                    $companyId,
                    $warehouseId,
                    $parentId
                )
        ) {
            $errors['parent_location_id'] =
                'Select an active parent from the same warehouse.';
        }

        $barcode = $values['barcode'];

        if (
            is_string($barcode)
            && (
                mb_strlen($barcode) > 120
                || $this->locations->barcodeExists(
                    $companyId,
                    $barcode
                )
            )
        ) {
            $errors['barcode'] =
                mb_strlen($barcode) > 120
                    ? 'Barcode cannot exceed 120 characters.'
                    : 'That barcode is already in use.';
        }

        foreach (
            [
                'aisle' => 'Aisle',
                'rack' => 'Rack',
                'shelf' => 'Shelf',
                'bin' => 'Bin',
            ]
            as $field => $label
        ) {
            if (
                is_string($values[$field])
                && mb_strlen($values[$field]) > 40
            ) {
                $errors[$field] =
                    $label . ' cannot exceed 40 characters.';
            }
        }

        if (
            !is_int($values['pick_priority'])
            || $values['pick_priority'] < 1
            || $values['pick_priority'] > 65535
        ) {
            $errors['pick_priority'] =
                'Pick priority must be between 1 and 65535.';
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
        $warehouseId = (int) $values['warehouse_id'];

        if ($this->locations->codeExists(
            $companyId,
            $warehouseId,
            (string) $values['code']
        )) {
            $errors['code'] =
                'That location code already exists in the warehouse.';
        }

        if (
            is_string($values['barcode'])
            && $this->locations->barcodeExists(
                $companyId,
                $values['barcode']
            )
        ) {
            $errors['barcode'] =
                'That barcode is already in use.';
        }

        if (
            is_int($values['parent_location_id'])
            && !$this->locations
                ->parentBelongsToWarehouse(
                    $companyId,
                    $warehouseId,
                    $values['parent_location_id']
                )
        ) {
            $errors['parent_location_id'] =
                'Select an active parent from the same warehouse.';
        }

        return $errors;
    }

    /** @return list<string> */
    private function defaultCodes(string $warehouseCode): array
    {
        return [
            $warehouseCode,
            $warehouseCode . '/INPUT',
            $warehouseCode . '/STOCK',
            $warehouseCode . '/OUTPUT',
            $warehouseCode . '/RETURNS',
            $warehouseCode . '/QUARANTINE',
        ];
    }

    private function optionalIntegerInput(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);

        return ctype_digit($value) && (int) $value > 0
            ? (int) $value
            : -1;
    }

    private function positiveIntegerInput(mixed $value): int
    {
        $value = trim((string) $value);

        return ctype_digit($value)
            ? (int) $value
            : -1;
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    private function nullableString(mixed $value): ?string
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
                    'The location could not be created because its code or barcode conflicts with existing data.',
            ],
        ];
    }
}
