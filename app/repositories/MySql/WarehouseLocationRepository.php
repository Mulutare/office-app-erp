<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\WarehouseLocationRepository
    as WarehouseLocationRepositoryContract;
use PDO;
use RuntimeException;

final class WarehouseLocationRepository extends MySqlRepository
    implements WarehouseLocationRepositoryContract
{
    public function warehouseForUpdate(
        int $companyId,
        int $warehouseId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                warehouse_id,
                code,
                name,
                active
             FROM inventory_warehouses
             WHERE company_id = :company_id
               AND warehouse_id = :warehouse_id
               AND deleted_at IS NULL
             FOR UPDATE'
        );
        $statement->execute([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
        ]);
        $warehouse = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($warehouse)) {
            throw new RuntimeException(
                'The warehouse was not found in the active company.'
            );
        }

        if (empty($warehouse['active'])) {
            throw new RuntimeException(
                'Locations cannot be maintained for an inactive warehouse.'
            );
        }

        return $warehouse;
    }

    public function activeWarehousesForCompany(
        int $companyId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT warehouse_id, code, name, is_default
             FROM inventory_warehouses
             WHERE company_id = :company_id
               AND active = TRUE
               AND deleted_at IS NULL
             ORDER BY is_default DESC, name'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
        $warehouses = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($warehouses) ? $warehouses : [];
    }

    public function warehouseBelongsToCompany(
        int $companyId,
        int $warehouseId
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM inventory_warehouses
             WHERE company_id = :company_id
               AND warehouse_id = :warehouse_id
               AND active = TRUE
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function listForCompany(int $companyId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT
                locations.location_id,
                locations.company_id,
                locations.warehouse_id,
                warehouses.code AS warehouse_code,
                warehouses.name AS warehouse_name,
                locations.parent_location_id,
                parents.code AS parent_code,
                parents.name AS parent_name,
                locations.code,
                locations.name,
                locations.location_type,
                locations.barcode,
                locations.aisle,
                locations.rack,
                locations.shelf,
                locations.bin,
                locations.pick_priority,
                locations.receiving_allowed,
                locations.picking_allowed,
                locations.active,
                locations.created_at,
                locations.updated_at
             FROM inventory_warehouse_locations locations
             INNER JOIN inventory_warehouses warehouses
               ON warehouses.company_id = locations.company_id
              AND warehouses.warehouse_id = locations.warehouse_id
              AND warehouses.deleted_at IS NULL
             LEFT JOIN inventory_warehouse_locations parents
               ON parents.company_id = locations.company_id
              AND parents.warehouse_id = locations.warehouse_id
              AND parents.location_id = locations.parent_location_id
              AND parents.deleted_at IS NULL
             WHERE locations.company_id = :company_id
               AND locations.deleted_at IS NULL
             ORDER BY
                warehouses.is_default DESC,
                warehouses.name,
                CASE
                    WHEN locations.parent_location_id IS NULL
                        THEN 0
                    ELSE 1
                END,
                locations.pick_priority,
                locations.code
             LIMIT 1000'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
        $locations = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($locations) ? $locations : [];
    }

    public function codeExists(
        int $companyId,
        int $warehouseId,
        string $code
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM inventory_warehouse_locations
             WHERE company_id = :company_id
               AND warehouse_id = :warehouse_id
               AND code = :code
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'code' => $code,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function barcodeExists(
        int $companyId,
        string $barcode
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM inventory_warehouse_locations
             WHERE company_id = :company_id
               AND barcode = :barcode
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'barcode' => $barcode,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function parentBelongsToWarehouse(
        int $companyId,
        int $warehouseId,
        int $parentLocationId
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM inventory_warehouse_locations
             WHERE company_id = :company_id
               AND warehouse_id = :warehouse_id
               AND location_id = :location_id
               AND active = TRUE
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'location_id' => $parentLocationId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function create(
        int $companyId,
        array $values,
        int $createdBy
    ): int {
        $statement = $this->connection()->prepare(
            'INSERT INTO inventory_warehouse_locations
                (
                    company_id,
                    warehouse_id,
                    parent_location_id,
                    code,
                    name,
                    location_type,
                    location_usage,
                    barcode,
                    aisle,
                    rack,
                    shelf,
                    bin,
                    pick_priority,
                    receiving_allowed,
                    picking_allowed,
                    active,
                    created_by,
                    updated_by
                )
             VALUES
                (
                    :company_id,
                    :warehouse_id,
                    :parent_location_id,
                    :code,
                    :name,
                    :location_type,
                    :location_usage,
                    :barcode,
                    :aisle,
                    :rack,
                    :shelf,
                    :bin,
                    :pick_priority,
                    :receiving_allowed,
                    :picking_allowed,
                    :active,
                    :created_by,
                    :updated_by
                )'
        );
        $statement->execute([
            'company_id' => $companyId,
            'warehouse_id' => $values['warehouse_id'],
            'parent_location_id' =>
                $values['parent_location_id'],
            'code' => $values['code'],
            'name' => $values['name'],
            'location_type' => $values['location_type'],
            'location_usage' => $values['usage'] ?? 'internal',
            'barcode' => $values['barcode'],
            'aisle' => $values['aisle'],
            'rack' => $values['rack'],
            'shelf' => $values['shelf'],
            'bin' => $values['bin'],
            'pick_priority' => $values['pick_priority'],
            'receiving_allowed' =>
                !empty($values['receiving_allowed']) ? 1 : 0,
            'picking_allowed' =>
                !empty($values['picking_allowed']) ? 1 : 0,
            'active' => !empty($values['active']) ? 1 : 0,
            'created_by' => $createdBy,
            'updated_by' => $createdBy,
        ]);

        return (int) $this->connection()->lastInsertId();
    }

    public function provisionOperationalDefaults(
        int $companyId,
        int $warehouseId,
        string $warehouseCode,
        string $warehouseName,
        int $createdBy
    ): array {
        $definitions = [
            'ROOT' => [
                'code' => $warehouseCode,
                'name' => $warehouseName,
                'type' => 'zone',
                'usage' => 'internal',
                'priority' => 1,
                'receiving' => false,
                'picking' => false,
                'parent' => null,
            ],
            'INPUT' => [
                'code' => $warehouseCode . '/INPUT',
                'name' => 'Input',
                'type' => 'receiving',
                'usage' => 'internal',
                'priority' => 10,
                'receiving' => true,
                'picking' => true,
                'parent' => 'ROOT',
            ],
            'STOCK' => [
                'code' => $warehouseCode . '/STOCK',
                'name' => 'Stock',
                'type' => 'zone',
                'usage' => 'internal',
                'priority' => 20,
                'receiving' => true,
                'picking' => true,
                'parent' => 'ROOT',
            ],
            'OUTPUT' => [
                'code' => $warehouseCode . '/OUTPUT',
                'name' => 'Output',
                'type' => 'dispatch',
                'usage' => 'internal',
                'priority' => 30,
                'receiving' => true,
                'picking' => true,
                'parent' => 'ROOT',
            ],
            'RETURNS' => [
                'code' => $warehouseCode . '/RETURNS',
                'name' => 'Returns',
                'type' => 'returns',
                'usage' => 'internal',
                'priority' => 40,
                'receiving' => true,
                'picking' => true,
                'parent' => 'ROOT',
            ],
            'QUARANTINE' => [
                'code' => $warehouseCode . '/QUARANTINE',
                'name' => 'Quarantine',
                'type' => 'quarantine',
                'usage' => 'internal',
                'priority' => 900,
                'receiving' => true,
                'picking' => false,
                'parent' => 'ROOT',
            ],
            'VENDOR' => [
                'code' => $warehouseCode . '/VENDOR', 'name' => 'Vendors',
                'type' => 'vendor', 'usage' => 'vendor', 'priority' => 950,
                'receiving' => true, 'picking' => true, 'parent' => 'ROOT',
            ],
            'CUSTOMER' => [
                'code' => $warehouseCode . '/CUSTOMER', 'name' => 'Customers',
                'type' => 'customer', 'usage' => 'customer', 'priority' => 951,
                'receiving' => true, 'picking' => true, 'parent' => 'ROOT',
            ],
            'INVENTORY' => [
                'code' => $warehouseCode . '/INVENTORY', 'name' => 'Inventory Adjustment',
                'type' => 'inventory', 'usage' => 'inventory', 'priority' => 952,
                'receiving' => true, 'picking' => true, 'parent' => 'ROOT',
            ],
            'SCRAP' => [
                'code' => $warehouseCode . '/SCRAP', 'name' => 'Scrap',
                'type' => 'scrap', 'usage' => 'scrap', 'priority' => 953,
                'receiving' => true, 'picking' => false, 'parent' => 'ROOT',
            ],
            'TRANSIT' => [
                'code' => $warehouseCode . '/TRANSIT', 'name' => 'Transit',
                'type' => 'transit', 'usage' => 'transit', 'priority' => 954,
                'receiving' => true, 'picking' => true, 'parent' => 'ROOT',
            ],
        ];

        $ids = [];

        foreach ($definitions as $key => $definition) {
            $parentId = null;
            $parentKey = $definition['parent'];

            if (is_string($parentKey)) {
                $parentId = $ids[$parentKey] ?? null;

                if (!is_int($parentId) || $parentId < 1) {
                    throw new RuntimeException(
                        'Operational location hierarchy could not be resolved.'
                    );
                }
            }

            $existing = $this->locationByCode(
                $companyId,
                $warehouseId,
                (string) $definition['code']
            );

            if (is_array($existing)) {
                if (
                    (string) ($existing['location_type'] ?? '')
                        !== $definition['type']
                    || $this->nullableInteger(
                        $existing['parent_location_id'] ?? null
                    ) !== $parentId
                    || empty($existing['active'])
                ) {
                    throw new RuntimeException(
                        sprintf(
                            'Location code %s already exists with a conflicting type or parent.',
                            $definition['code']
                        )
                    );
                }

                $ids[$key] = (int) $existing['location_id'];
                continue;
            }

            $ids[$key] = $this->create(
                $companyId,
                [
                    'warehouse_id' => $warehouseId,
                    'parent_location_id' => $parentId,
                    'code' => $definition['code'],
                    'name' => $definition['name'],
                    'location_type' => $definition['type'],
                    'usage' => $definition['usage'],
                    'barcode' => null,
                    'aisle' => null,
                    'rack' => null,
                    'shelf' => null,
                    'bin' => null,
                    'pick_priority' => $definition['priority'],
                    'receiving_allowed' =>
                        $definition['receiving'],
                    'picking_allowed' =>
                        $definition['picking'],
                    'active' => true,
                ],
                $createdBy
            );
        }

        return $ids;
    }

    public function configureDefaultOperationLocations(
        int $companyId,
        int $warehouseId,
        array $locations
    ): void {
        foreach (
            ['ROOT', 'INPUT', 'STOCK', 'OUTPUT', 'RETURNS', 'QUARANTINE',
             'VENDOR', 'CUSTOMER', 'INVENTORY', 'SCRAP', 'TRANSIT']
            as $required
        ) {
            if (
                !isset($locations[$required])
                || (int) $locations[$required] < 1
            ) {
                throw new RuntimeException(
                    'Operational location provisioning was incomplete.'
                );
            }
        }

        $statement = $this->connection()->prepare(
            'UPDATE inventory_operation_types
             SET default_source_location_id = CASE
                    WHEN operation_kind = \'receipt\' THEN :receipt_source
                    WHEN operation_kind = \'internal_transfer\'
                        THEN :internal_source
                    WHEN operation_kind = \'delivery\'
                        THEN :delivery_source
                    WHEN operation_kind = \'adjustment\' THEN :adjustment_source
                    ELSE default_source_location_id
                 END,
                 default_destination_location_id = CASE
                    WHEN operation_kind = \'receipt\'
                        THEN :receipt_destination
                    WHEN operation_kind = \'internal_transfer\'
                        THEN :internal_destination
                    WHEN operation_kind = \'delivery\' THEN :delivery_destination
                    WHEN operation_kind = \'adjustment\'
                        THEN :adjustment_destination
                    ELSE default_destination_location_id
                 END
             WHERE company_id = :company_id
               AND warehouse_id = :warehouse_id
               AND is_default = TRUE
               AND active = TRUE
               AND operation_kind IN (
                    \'receipt\',
                    \'internal_transfer\',
                    \'delivery\',
                    \'adjustment\'
               )'
        );
        $statement->execute([
            'receipt_source' => $locations['VENDOR'],
            'internal_source' => $locations['STOCK'],
            'delivery_source' => $locations['STOCK'],
            'adjustment_source' => $locations['INVENTORY'],
            'receipt_destination' => $locations['INPUT'],
            'internal_destination' => $locations['OUTPUT'],
            'delivery_destination' => $locations['CUSTOMER'],
            'adjustment_destination' => $locations['INVENTORY'],
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
        ]);

        $verification = $this->connection()->prepare(
            'SELECT COUNT(*)
             FROM inventory_operation_types operation_types
             WHERE operation_types.company_id = :company_id
               AND operation_types.warehouse_id = :warehouse_id
               AND operation_types.is_default = TRUE
               AND operation_types.active = TRUE
               AND (
                    (
                        operation_types.operation_kind = \'receipt\'
                        AND operation_types.default_source_location_id
                            = :vendor_id
                        AND operation_types.default_destination_location_id
                            = :input_id
                    )
                    OR (
                        operation_types.operation_kind =
                            \'internal_transfer\'
                        AND operation_types.default_source_location_id
                            = :internal_stock_id
                        AND operation_types.default_destination_location_id
                            = :internal_output_id
                    )
                    OR (
                        operation_types.operation_kind = \'delivery\'
                        AND operation_types.default_source_location_id
                            = :delivery_stock_id
                        AND operation_types.default_destination_location_id
                            = :customer_id
                    )
                    OR (
                        operation_types.operation_kind = \'adjustment\'
                        AND operation_types.default_source_location_id
                            = :adjustment_source_id
                        AND operation_types.default_destination_location_id
                            = :adjustment_destination_id
                    )
               )'
        );
        $verification->execute([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'vendor_id' => $locations['VENDOR'],
            'input_id' => $locations['INPUT'],
            'internal_stock_id' => $locations['STOCK'],
            'internal_output_id' => $locations['OUTPUT'],
            'delivery_stock_id' => $locations['STOCK'],
            'customer_id' => $locations['CUSTOMER'],
            'adjustment_source_id' => $locations['INVENTORY'],
            'adjustment_destination_id' => $locations['INVENTORY'],
        ]);

        if ((int) $verification->fetchColumn() !== 4) {
            throw new RuntimeException(
                'Default operation locations could not be configured.'
            );
        }
    }

    public function readinessForCompany(
        int $companyId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                warehouses.warehouse_id,
                (
                    SELECT COUNT(*)
                    FROM inventory_warehouse_locations locations
                    LEFT JOIN inventory_warehouse_locations parents
                      ON parents.company_id = locations.company_id
                     AND parents.warehouse_id =
                            locations.warehouse_id
                     AND parents.location_id =
                            locations.parent_location_id
                     AND parents.active = TRUE
                     AND parents.deleted_at IS NULL
                    WHERE locations.company_id =
                        warehouses.company_id
                      AND locations.warehouse_id =
                        warehouses.warehouse_id
                      AND locations.active = TRUE
                      AND locations.deleted_at IS NULL
                      AND (
                          (
                              locations.code = warehouses.code
                              AND locations.location_type = \'zone\'
                              AND locations.parent_location_id IS NULL
                              AND locations.receiving_allowed = FALSE
                              AND locations.picking_allowed = FALSE
                          )
                          OR (
                              locations.code =
                                  CONCAT(warehouses.code, \'/INPUT\')
                              AND locations.location_type =
                                  \'receiving\'
                              AND parents.code = warehouses.code
                              AND locations.receiving_allowed = TRUE
                              AND locations.picking_allowed = TRUE
                          )
                          OR (
                              locations.code =
                                  CONCAT(warehouses.code, \'/STOCK\')
                              AND locations.location_type = \'zone\'
                              AND parents.code = warehouses.code
                              AND locations.receiving_allowed = TRUE
                              AND locations.picking_allowed = TRUE
                          )
                          OR (
                              locations.code =
                                  CONCAT(warehouses.code, \'/OUTPUT\')
                              AND locations.location_type = \'dispatch\'
                              AND parents.code = warehouses.code
                              AND locations.receiving_allowed = TRUE
                              AND locations.picking_allowed = TRUE
                          )
                          OR (
                              locations.code =
                                  CONCAT(warehouses.code, \'/RETURNS\')
                              AND locations.location_type = \'returns\'
                              AND parents.code = warehouses.code
                              AND locations.receiving_allowed = TRUE
                              AND locations.picking_allowed = TRUE
                          )
                          OR (
                              locations.code =
                                  CONCAT(
                                      warehouses.code,
                                      \'/QUARANTINE\'
                                  )
                              AND locations.location_type =
                                  \'quarantine\'
                              AND parents.code = warehouses.code
                              AND locations.receiving_allowed = TRUE
                              AND locations.picking_allowed = FALSE
                          )
                      )
                ) AS operational_location_count,
                (
                    SELECT COUNT(*)
                    FROM inventory_operation_types operation_types
                    LEFT JOIN inventory_warehouse_locations source_locations
                      ON source_locations.company_id =
                            operation_types.company_id
                     AND source_locations.warehouse_id =
                            operation_types.warehouse_id
                     AND source_locations.location_id =
                            operation_types.default_source_location_id
                     AND source_locations.active = TRUE
                     AND source_locations.deleted_at IS NULL
                    LEFT JOIN inventory_warehouse_locations
                        destination_locations
                      ON destination_locations.company_id =
                            operation_types.company_id
                     AND destination_locations.warehouse_id =
                            operation_types.warehouse_id
                     AND destination_locations.location_id =
                            operation_types.default_destination_location_id
                     AND destination_locations.active = TRUE
                     AND destination_locations.deleted_at IS NULL
                    WHERE operation_types.company_id =
                            warehouses.company_id
                      AND operation_types.warehouse_id =
                            warehouses.warehouse_id
                      AND operation_types.is_default = TRUE
                      AND operation_types.active = TRUE
                      AND (
                          (
                              operation_types.operation_kind =
                                  \'receipt\'
                              AND source_locations.code =
                                  CONCAT(warehouses.code, \'/VENDOR\')
                              AND destination_locations.code =
                                  CONCAT(warehouses.code, \'/INPUT\')
                          )
                          OR (
                              operation_types.operation_kind =
                                  \'internal_transfer\'
                              AND source_locations.code =
                                  CONCAT(warehouses.code, \'/STOCK\')
                              AND destination_locations.code =
                                  CONCAT(warehouses.code, \'/OUTPUT\')
                          )
                          OR (
                              operation_types.operation_kind =
                                  \'delivery\'
                              AND source_locations.code =
                                  CONCAT(warehouses.code, \'/STOCK\')
                              AND destination_locations.code =
                                  CONCAT(warehouses.code, \'/CUSTOMER\')
                          )
                          OR (
                              operation_types.operation_kind =
                                  \'adjustment\'
                              AND source_locations.code =
                                  CONCAT(warehouses.code, \'/INVENTORY\')
                              AND destination_locations.code =
                                  CONCAT(warehouses.code, \'/INVENTORY\')
                          )
                      )
                ) AS mapped_operation_type_count
             FROM inventory_warehouses warehouses
             WHERE warehouses.company_id = :company_id
               AND warehouses.deleted_at IS NULL
             ORDER BY warehouses.warehouse_id'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed>|null */
    private function locationByCode(
        int $companyId,
        int $warehouseId,
        string $code
    ): ?array {
        $statement = $this->connection()->prepare(
            'SELECT
                location_id,
                parent_location_id,
                location_type,
                active
             FROM inventory_warehouse_locations
             WHERE company_id = :company_id
               AND warehouse_id = :warehouse_id
               AND code = :code
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'code' => $code,
        ]);
        $location = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($location) ? $location : null;
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }
}
