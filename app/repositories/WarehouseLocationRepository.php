<?php

declare(strict_types=1);

namespace App\Repositories;

interface WarehouseLocationRepository
{
    /**
     * @return array{
     *     warehouse_id: int|string,
     *     code: string,
     *     name: string,
     *     active: int|string
     * }
     */
    public function warehouseForUpdate(
        int $companyId,
        int $warehouseId
    ): array;

    /** @return list<array<string, mixed>> */
    public function activeWarehousesForCompany(
        int $companyId
    ): array;

    public function warehouseBelongsToCompany(
        int $companyId,
        int $warehouseId
    ): bool;

    /** @return list<array<string, mixed>> */
    public function listForCompany(int $companyId): array;

    public function codeExists(
        int $companyId,
        int $warehouseId,
        string $code
    ): bool;

    public function barcodeExists(
        int $companyId,
        string $barcode
    ): bool;

    public function parentBelongsToWarehouse(
        int $companyId,
        int $warehouseId,
        int $parentLocationId
    ): bool;

    /** @param array<string, mixed> $values */
    public function create(
        int $companyId,
        array $values,
        int $createdBy
    ): int;

    /**
     * @return array{
     *     ROOT: int,
     *     INPUT: int,
     *     STOCK: int,
     *     OUTPUT: int,
     *     RETURNS: int,
     *     QUARANTINE: int
     * }
     */
    public function provisionOperationalDefaults(
        int $companyId,
        int $warehouseId,
        string $warehouseCode,
        string $warehouseName,
        int $createdBy
    ): array;

    /**
     * @param array{
     *     ROOT: int,
     *     INPUT: int,
     *     STOCK: int,
     *     OUTPUT: int,
     *     RETURNS: int,
     *     QUARANTINE: int
     * } $locations
     */
    public function configureDefaultOperationLocations(
        int $companyId,
        int $warehouseId,
        array $locations
    ): void;

    /**
     * @return list<array{
     *     warehouse_id: int|string,
     *     operational_location_count: int|string,
     *     mapped_operation_type_count: int|string
     * }>
     */
    public function readinessForCompany(
        int $companyId
    ): array;
}
