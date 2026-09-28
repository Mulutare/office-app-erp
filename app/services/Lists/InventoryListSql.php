<?php
declare(strict_types=1);
namespace App\Services\Lists;

/** Authoritative warehouse configuration projections, shared with legacy repositories. */
final class InventoryListSql
{
    public static function warehouses(): string
    {
        return 'SELECT
                warehouses.company_id,
                warehouses.warehouse_id,
                warehouses.code,
                warehouses.name,
                warehouses.warehouse_type,
                warehouses.branch_id,
                branches.name AS branch_name,
                warehouses.manager_user_id,
                COALESCE(
                    NULLIF(managers.display_name, \'\'),
                    managers.username
                ) AS manager_name,
                warehouses.address,
                warehouses.phone,
                warehouses.email,
                warehouses.allow_negative_stock,
                warehouses.is_default,
                warehouses.active,
                warehouses.created_at,
                warehouses.updated_at,
                COUNT(operation_types.operation_type_id)
                    AS active_operation_type_count,
                SUM(
                    CASE
                        WHEN operation_types.is_default = TRUE
                            THEN 1
                        ELSE 0
                    END
                ) AS active_default_operation_type_count
             FROM inventory_warehouses warehouses
             LEFT JOIN organization_branches branches
               ON branches.company_id = warehouses.company_id
              AND branches.branch_id = warehouses.branch_id
              AND branches.deleted_at IS NULL
             LEFT JOIN company_users manager_memberships
               ON manager_memberships.company_id =
                    warehouses.company_id
              AND manager_memberships.user_id =
                    warehouses.manager_user_id
              AND manager_memberships.active = TRUE
             LEFT JOIN users managers
               ON managers.user_id =
                    manager_memberships.user_id
              AND managers.active = TRUE
              AND managers.deleted_at IS NULL
             LEFT JOIN inventory_operation_types operation_types
               ON operation_types.company_id = warehouses.company_id
              AND operation_types.warehouse_id = warehouses.warehouse_id
              AND operation_types.active = TRUE
             WHERE warehouses.company_id = :company_id
               AND warehouses.deleted_at IS NULL
             GROUP BY
                warehouses.company_id,
                warehouses.warehouse_id,
                warehouses.code,
                warehouses.name,
                warehouses.warehouse_type,
                warehouses.branch_id,
                branches.name,
                warehouses.manager_user_id,
                managers.display_name,
                managers.username,
                warehouses.address,
                warehouses.phone,
                warehouses.email,
                warehouses.allow_negative_stock,
                warehouses.is_default,
                warehouses.active,
                warehouses.created_at,
                warehouses.updated_at';
    }

    public static function locations(): string
    {
        return 'SELECT
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
               AND locations.deleted_at IS NULL';
    }

    public static function readiness(): string
    {
        return 'SELECT
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
               AND warehouses.deleted_at IS NULL';
    }

}
