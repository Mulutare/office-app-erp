<?php

declare(strict_types=1);

namespace App\Repositories\MySql;

use App\Repositories\WarehouseRepository as WarehouseRepositoryContract;
use RuntimeException;

final class WarehouseRepository extends MySqlRepository
    implements WarehouseRepositoryContract
{
    public function lockCompany(int $companyId): void
    {
        $statement = $this->connection()->prepare(
            'SELECT company_id
             FROM companies
             WHERE company_id = :company_id
               AND active = TRUE
               AND deleted_at IS NULL
             FOR UPDATE'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);

        if ($statement->fetchColumn() === false) {
            throw new RuntimeException(
                'The active company workspace was not found.'
            );
        }
    }

    public function listForCompany(int $companyId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT
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
                warehouses.updated_at
             ORDER BY
                warehouses.is_default DESC,
                warehouses.active DESC,
                warehouses.name
             LIMIT 250'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
        $warehouses = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($warehouses)
            ? $warehouses
            : [];
    }

    public function codeExists(
        int $companyId,
        string $code
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM inventory_warehouses
             WHERE company_id = :company_id
               AND code = :code
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'code' => $code,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function defaultWarehouseId(
        int $companyId,
        bool $lock = false
    ): ?int {
        $sql = 'SELECT warehouse_id
                FROM inventory_warehouses
                WHERE company_id = :company_id
                  AND is_default = TRUE
                  AND deleted_at IS NULL
                ORDER BY warehouse_id
                LIMIT 1';

        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->connection()->prepare($sql);
        $statement->execute([
            'company_id' => $companyId,
        ]);
        $warehouseId = $statement->fetchColumn();

        return $warehouseId === false
            ? null
            : (int) $warehouseId;
    }

    public function branchBelongsToCompany(
        int $companyId,
        int $branchId
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM organization_branches
             WHERE company_id = :company_id
               AND branch_id = :branch_id
               AND active = TRUE
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'branch_id' => $branchId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function managerBelongsToCompany(
        int $companyId,
        int $userId
    ): bool {
        $statement = $this->connection()->prepare(
            'SELECT 1
             FROM company_users memberships
             INNER JOIN users
               ON users.user_id = memberships.user_id
             WHERE memberships.company_id = :company_id
               AND memberships.user_id = :user_id
               AND memberships.active = TRUE
               AND users.active = TRUE
               AND users.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function activeBranchesForCompany(
        int $companyId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT branch_id, code, name
             FROM organization_branches
             WHERE company_id = :company_id
               AND active = TRUE
               AND deleted_at IS NULL
             ORDER BY is_head_office DESC, name'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
        $branches = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($branches) ? $branches : [];
    }

    public function activeManagersForCompany(
        int $companyId
    ): array {
        $statement = $this->connection()->prepare(
            'SELECT
                users.user_id,
                users.username,
                COALESCE(
                    NULLIF(users.display_name, \'\'),
                    users.username
                ) AS display_name
             FROM company_users memberships
             INNER JOIN users
               ON users.user_id = memberships.user_id
             WHERE memberships.company_id = :company_id
               AND memberships.active = TRUE
               AND users.active = TRUE
               AND users.deleted_at IS NULL
             ORDER BY display_name, users.username'
        );
        $statement->execute([
            'company_id' => $companyId,
        ]);
        $managers = $statement->fetchAll(
            \PDO::FETCH_ASSOC
        );

        return is_array($managers) ? $managers : [];
    }

    public function create(
        int $companyId,
        array $values,
        int $createdBy
    ): int {
        $statement = $this->connection()->prepare(
            'INSERT INTO inventory_warehouses
                (
                    company_id,
                    branch_id,
                    manager_user_id,
                    code,
                    name,
                    warehouse_type,
                    address,
                    phone,
                    email,
                    allow_negative_stock,
                    is_default,
                    active,
                    created_by,
                    updated_by
                )
             VALUES
                (
                    :company_id,
                    :branch_id,
                    :manager_user_id,
                    :code,
                    :name,
                    :warehouse_type,
                    :address,
                    :phone,
                    :email,
                    :allow_negative_stock,
                    :is_default,
                    :active,
                    :created_by,
                    :updated_by
                )'
        );
        $statement->execute([
            'company_id' => $companyId,
            'branch_id' => $values['branch_id'],
            'manager_user_id' => $values['manager_user_id'],
            'code' => $values['code'],
            'name' => $values['name'],
            'warehouse_type' => $values['warehouse_type'],
            'address' => $values['address'],
            'phone' => $values['phone'],
            'email' => $values['email'],
            'allow_negative_stock' => !empty(
                $values['allow_negative_stock']
            ) ? 1 : 0,
            'is_default' => !empty($values['is_default'])
                ? 1
                : 0,
            'active' => !empty($values['active'])
                ? 1
                : 0,
            'created_by' => $createdBy,
            'updated_by' => $createdBy,
        ]);

        return (int) $this->connection()->lastInsertId();
    }

    public function createDefaultOperationTypes(
        int $companyId,
        int $warehouseId
    ): void {
        $statement = $this->connection()->prepare(
            'INSERT INTO inventory_operation_types
                (
                    company_id,
                    warehouse_id,
                    code,
                    name,
                    operation_kind,
                    requires_approval,
                    auto_reserve,
                    allow_partial,
                    create_backorder,
                    is_default,
                    active
                )
             VALUES
                (
                    :receipt_company_id,
                    :receipt_warehouse_id,
                    \'RCPT\',
                    \'Receipts\',
                    \'receipt\',
                    TRUE,
                    FALSE,
                    TRUE,
                    TRUE,
                    TRUE,
                    TRUE
                ),
                (
                    :transfer_company_id,
                    :transfer_warehouse_id,
                    \'INT\',
                    \'Internal Transfers\',
                    \'internal_transfer\',
                    FALSE,
                    FALSE,
                    TRUE,
                    TRUE,
                    TRUE,
                    TRUE
                ),
                (
                    :delivery_company_id,
                    :delivery_warehouse_id,
                    \'DLV\',
                    \'Delivery Orders\',
                    \'delivery\',
                    FALSE,
                    TRUE,
                    TRUE,
                    TRUE,
                    TRUE,
                    TRUE
                ),
                (
                    :adjustment_company_id,
                    :adjustment_warehouse_id,
                    \'ADJ\',
                    \'Inventory Adjustments\',
                    \'adjustment\',
                    TRUE,
                    FALSE,
                    FALSE,
                    FALSE,
                    TRUE,
                    TRUE
                )
             ON DUPLICATE KEY UPDATE
                name=VALUES(name),operation_kind=VALUES(operation_kind),
                requires_approval=VALUES(requires_approval),auto_reserve=VALUES(auto_reserve),
                allow_partial=VALUES(allow_partial),create_backorder=VALUES(create_backorder),
                is_default=TRUE,active=TRUE'
        );
        $statement->execute([
            'receipt_company_id' => $companyId,
            'receipt_warehouse_id' => $warehouseId,
            'transfer_company_id' => $companyId,
            'transfer_warehouse_id' => $warehouseId,
            'delivery_company_id' => $companyId,
            'delivery_warehouse_id' => $warehouseId,
            'adjustment_company_id' => $companyId,
            'adjustment_warehouse_id' => $warehouseId,
        ]);

        $verification=$this->connection()->prepare("SELECT COUNT(*) FROM inventory_operation_types WHERE company_id=:company_id AND warehouse_id=:warehouse_id AND active=TRUE AND is_default=TRUE AND ((code='RCPT' AND operation_kind='receipt') OR (code='INT' AND operation_kind='internal_transfer') OR (code='DLV' AND operation_kind='delivery') OR (code='ADJ' AND operation_kind='adjustment'))");
        $verification->execute(['company_id'=>$companyId,'warehouse_id'=>$warehouseId]);
        if ((int)$verification->fetchColumn() !== 4) {
            throw new RuntimeException(
                'Warehouse operation-type provisioning was incomplete.'
            );
        }
    }
}
